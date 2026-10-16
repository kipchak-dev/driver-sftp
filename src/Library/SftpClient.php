<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Library;

use Closure;
use InvalidArgumentException;
use Kipchak\Driver\Sftp\Exception\AuthenticationException;
use Kipchak\Driver\Sftp\Exception\FileNotFoundException;
use Kipchak\Driver\Sftp\Exception\HostKeyVerificationException;
use Kipchak\Driver\Sftp\Exception\SftpConnectionException;
use Kipchak\Driver\Sftp\Exception\SftpException;
use Kipchak\Driver\Sftp\Result\FileInfo;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * An SFTP connection to one server.
 *
 * The connection is opened on first use and kept for the life of the worker, so requests after the first do not
 * pay for the SSH handshake. A server closes idle connections, so a connection that was opened by an earlier call
 * and turns out to be dead is re-opened and the operation tried once more. Every operation here is safe to repeat:
 * writes go to a temporary file first, so a repeated write never leaves a partial file behind.
 *
 * With a root, every path is resolved inside it and a path that would climb out of it ("../") is rejected, so
 * paths built from request data cannot reach the rest of the server.
 */
class SftpClient
{
    private ?SFTP $sftp = null;

    /** @var Closure(string, int, int): SFTP */
    private readonly Closure $connector;

    /**
     * @param list<string> $hostKeyFingerprints SHA256 fingerprints as OpenSSH prints them, e.g. "SHA256:uNiVz...".
     * @param ?Closure(string, int, int): SFTP $connector Creates the phpseclib connection; replaced in tests.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly ?string $password = null,
        private readonly ?PrivateKey $privateKey = null,
        private readonly array $hostKeyFingerprints = [],
        private readonly bool $verifyHostKey = true,
        private readonly int $port = 22,
        private readonly ?string $root = null,
        private readonly int $timeout = 10,
        private readonly bool $atomicWrites = true,
        ?Closure $connector = null,
    ) {
        if ($this->password === null && $this->privateKey === null) {
            throw new InvalidArgumentException('An SFTP connection needs a password, a private key or both.');
        }
        if ($this->verifyHostKey && $this->hostKeyFingerprints === []) {
            throw new InvalidArgumentException('An SFTP connection needs the server\'s host key fingerprints, or verify_host_key set to false.');
        }

        $this->connector = $connector ?? static fn (string $host, int $port, int $timeout): SFTP => new SFTP($host, $port, $timeout);
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * The files and directories in a directory, sorted by name. $pattern is a shell wildcard matched against the
     * name, such as "*.csv".
     *
     * @return list<FileInfo>
     */
    public function list(string $directory = '', ?string $pattern = null): array
    {
        $remote = $this->resolve($directory);

        return $this->call(function (SFTP $sftp) use ($directory, $remote, $pattern): array {
            $entries = $sftp->rawlist($remote);
            if (!is_array($entries)) {
                throw $this->failure($sftp, 'Could not list ' . $directory, $directory);
            }

            $files = [];
            foreach ($entries as $name => $attributes) {
                $name = (string) $name;
                if ($name === '.' || $name === '..' || ($pattern !== null && !fnmatch($pattern, $name))) {
                    continue;
                }
                $files[] = FileInfo::fromAttributes($this->clientPath($directory === '' ? $name : $directory . '/' . $name), $attributes);
            }
            usort($files, static fn (FileInfo $a, FileInfo $b): int => strcmp($a->name, $b->name));

            return $files;
        });
    }

    /**
     * The regular files in a directory, sorted by name: what a batch job picks up.
     *
     * @return list<FileInfo>
     */
    public function files(string $directory = '', ?string $pattern = null): array
    {
        return array_values(array_filter($this->list($directory, $pattern), static fn (FileInfo $file): bool => $file->isFile()));
    }

    /**
     * The file or directory at $path, or null when there is none. Symbolic links are followed.
     */
    public function stat(string $path): ?FileInfo
    {
        $remote = $this->resolve($path);

        return $this->call(function (SFTP $sftp) use ($path, $remote): ?FileInfo {
            $attributes = $sftp->stat($remote);
            if (!is_array($attributes)) {
                $error = $this->failure($sftp, 'Could not read the details of ' . $path, $path);
                if ($error instanceof FileNotFoundException) {
                    return null;
                }
                throw $error;
            }

            return FileInfo::fromAttributes($this->clientPath($path), $attributes);
        });
    }

    public function exists(string $path): bool
    {
        return $this->stat($path) !== null;
    }

    /**
     * The contents of a remote file. Use download() or readStream() for files too large to hold in memory.
     */
    public function read(string $path): string
    {
        $remote = $this->resolve($path);

        return $this->call(function (SFTP $sftp) use ($path, $remote): string {
            $contents = $sftp->get($remote);
            if (!is_string($contents)) {
                throw $this->failure($sftp, 'Could not read ' . $path, $path);
            }

            return $contents;
        });
    }

    /**
     * Downloads a remote file to a local file, streaming it rather than holding it in memory. The local file
     * appears only once the download is complete.
     */
    public function download(string $path, string $localFile): void
    {
        $remote = $this->resolve($path);
        $partial = $localFile . '.part';

        $this->call(function (SFTP $sftp) use ($path, $remote, $localFile, $partial): void {
            if ($sftp->get($remote, $partial) === false) {
                @unlink($partial);
                throw $this->failure($sftp, 'Could not download ' . $path, $path);
            }
            if (!rename($partial, $localFile)) {
                @unlink($partial);
                throw new SftpException('Downloaded ' . $path . ' but could not write ' . $localFile, $path);
            }
        });
    }

    /**
     * The contents of a remote file as a stream, positioned at the start. Large files are held on local disk
     * (php://temp) rather than in memory.
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        $remote = $this->resolve($path);

        return $this->call(function (SFTP $sftp) use ($path, $remote) {
            $stream = fopen('php://temp', 'w+b');
            if ($sftp->get($remote, $stream) === false) {
                fclose($stream);
                throw $this->failure($sftp, 'Could not read ' . $path, $path);
            }
            rewind($stream);

            return $stream;
        });
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Writes a string to a remote file, replacing any file already there.
     */
    public function write(string $path, string $contents): void
    {
        $this->transfer($path, $contents, SFTP::SOURCE_STRING, strlen($contents));
    }

    /**
     * Uploads a local file, streaming it rather than holding it in memory, replacing any remote file already there.
     */
    public function upload(string $localFile, string $path): void
    {
        if (!is_file($localFile) || !is_readable($localFile)) {
            throw new SftpException('Cannot upload ' . $localFile . ': it is not a readable file', $path);
        }

        $this->transfer($path, $localFile, SFTP::SOURCE_LOCAL_FILE, (int) filesize($localFile));
    }

    /**
     * Uploads everything from a stream's current position to its end, replacing any remote file already there.
     * The stream must be seekable, so that the upload can be repeated if the connection drops.
     *
     * @param resource $stream
     */
    public function writeStream(string $path, $stream): void
    {
        if (!is_resource($stream) || !stream_get_meta_data($stream)['seekable']) {
            throw new InvalidArgumentException('writeStream() needs a seekable stream.');
        }

        $start = (int) ftell($stream);
        $size = fstat($stream)['size'] ?? null;
        $this->transfer($path, $stream, SFTP::SOURCE_STRING, $size === null ? null : $size - $start, $start);
    }

    /**
     * Renames or moves a file or directory. A file already at $to is replaced only when $overwrite is true; the
     * replacement is atomic on servers with the posix-rename extension, such as OpenSSH.
     */
    public function move(string $from, string $to, bool $overwrite = false): void
    {
        $source = $this->resolve($from);
        $destination = $this->resolve($to);

        $this->call(function (SFTP $sftp) use ($from, $to, $source, $destination, $overwrite): void {
            if (!$overwrite && $sftp->stat($destination) !== false) {
                throw new SftpException('Cannot move ' . $from . ' to ' . $to . ': ' . $to . ' already exists', $to);
            }
            if (!$this->rename($sftp, $source, $destination, $overwrite)) {
                throw $this->failure($sftp, 'Could not move ' . $from . ' to ' . $to, $from);
            }
        });
    }

    /**
     * Deletes a file. Use deleteDirectory() for directories.
     */
    public function delete(string $path): void
    {
        $remote = $this->resolve($path);

        $this->call(function (SFTP $sftp) use ($path, $remote): void {
            if (!$sftp->delete($remote, false)) {
                throw $this->failure($sftp, 'Could not delete ' . $path, $path);
            }
        });
    }

    /**
     * Creates a directory and any missing parents. A directory that already exists is not an error.
     */
    public function createDirectory(string $path): void
    {
        $remote = $this->resolve($path);

        $this->call(function (SFTP $sftp) use ($path, $remote): void {
            if ($sftp->mkdir($remote, -1, true) || $sftp->is_dir($remote)) {
                return;
            }
            throw $this->failure($sftp, 'Could not create the directory ' . $path, $path);
        });
    }

    /**
     * Deletes a directory and everything in it.
     */
    public function deleteDirectory(string $path): void
    {
        $remote = $this->resolve($path);
        if ($this->root !== null && self::normalise($path) === '') {
            throw new InvalidArgumentException('deleteDirectory() will not delete the connection\'s root.');
        }

        $this->call(function (SFTP $sftp) use ($path, $remote): void {
            if (!$sftp->is_dir($remote)) {
                throw new FileNotFoundException('Could not delete ' . $path . ': it is not a directory', $path);
            }
            if (!$sftp->delete($remote, true)) {
                throw $this->failure($sftp, 'Could not delete the directory ' . $path, $path);
            }
        });
    }

    /**
     * Closes the connection. The next operation opens a new one.
     */
    public function disconnect(): void
    {
        if ($this->sftp !== null) {
            try {
                $this->sftp->disconnect();
            } catch (Throwable) {
            }
            $this->sftp = null;
        }
    }

    /**
     * The OpenSSH SHA256 fingerprint of a host key as phpseclib reports it ("ssh-ed25519 AAAAC3Nza...").
     */
    public static function fingerprint(string $hostKey): string
    {
        $parts = explode(' ', $hostKey);
        $blob = base64_decode($parts[1] ?? $parts[0], true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob === false ? '' : $blob, true)), '=');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Writes to a temporary file next to the destination, checks that all of it arrived, then renames it into
     * place, so that a program collecting files from the directory never sees a partial one.
     *
     * @param string|resource $data
     */
    private function transfer(string $path, mixed $data, int $mode, ?int $expectedSize, ?int $streamStart = null): void
    {
        $destination = $this->resolve($path);
        if (self::normalise($path) === '') {
            throw new InvalidArgumentException('A file name is required.');
        }

        $this->call(function (SFTP $sftp) use ($path, $data, $mode, $expectedSize, $streamStart, $destination): void {
            if ($streamStart !== null && fseek($data, $streamStart) !== 0) {
                throw new SftpException('Could not rewind the stream to upload ' . $path, $path);
            }

            $target = $this->atomicWrites
                ? dirname($destination) . '/.' . basename($destination) . '.' . bin2hex(random_bytes(4)) . '.part'
                : $destination;

            try {
                if (!$sftp->put($target, $data, $mode)) {
                    throw $this->failure($sftp, 'Could not write ' . $path, $path);
                }

                if ($expectedSize !== null) {
                    $written = $sftp->stat($target);
                    if (is_array($written) && isset($written['size']) && (int) $written['size'] !== $expectedSize) {
                        throw new SftpException(
                            'Wrote ' . $path . ' but the server has ' . $written['size'] . ' of ' . $expectedSize . ' bytes',
                            $path,
                        );
                    }
                }

                if ($target !== $destination && !$this->rename($sftp, $target, $destination, true)) {
                    throw $this->failure($sftp, 'Wrote ' . $path . ' but could not move it into place', $path);
                }
            } catch (Throwable $e) {
                if ($target !== $destination && $sftp->isConnected()) {
                    try {
                        $sftp->delete($target, false);
                    } catch (Throwable) {
                    }
                }
                throw $e;
            }
        });
    }

    /**
     * Renames with posix-rename when replacing a file, which replaces it atomically. Servers without that extension
     * get the file deleted first, which leaves a moment when no file is there.
     */
    private function rename(SFTP $sftp, string $from, string $to, bool $overwrite): bool
    {
        if (!$overwrite) {
            return $sftp->rename($from, $to);
        }

        $extensions = $sftp->getSupportedExtensions();
        if (is_array($extensions) && ($extensions['posix-rename@openssh.com'] ?? null) === '1') {
            return $sftp->posix_rename($from, $to);
        }

        $existing = $sftp->stat($to);
        if (is_array($existing) && !$sftp->delete($to, false)) {
            return false;
        }

        return $sftp->rename($from, $to);
    }

    /**
     * Runs an operation on the connection. When a connection opened by an earlier call has died (the server closed
     * it while idle), it is re-opened and the operation tried once more.
     *
     * @template T
     * @param Closure(SFTP): T $operation
     * @return T
     */
    private function call(Closure $operation): mixed
    {
        $reused = $this->sftp !== null;

        while (true) {
            $sftp = $this->connection();

            try {
                return $operation($sftp);
            } catch (SftpException $e) {
                if ($sftp->isConnected()) {
                    throw $e;
                }
                $error = $e;
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (Throwable $e) {
                $error = $e;
            }

            $this->disconnect();
            if (!$reused) {
                throw $error instanceof SftpConnectionException ? $error : new SftpConnectionException(
                    'Lost the connection to ' . $this->address() . ': ' . $error->getMessage(),
                    $error instanceof SftpException ? $error->path : null,
                    previous: $error,
                );
            }
            $reused = false;
        }
    }

    /**
     * The open connection, or a new one: connected, the host key checked against the configured fingerprints, and
     * only then logged in.
     */
    private function connection(): SFTP
    {
        if ($this->sftp !== null && $this->sftp->isConnected()) {
            return $this->sftp;
        }
        $this->disconnect();

        try {
            $sftp = ($this->connector)($this->host, $this->port, $this->timeout);
            $sftp->setTimeout($this->timeout);
            $hostKey = $sftp->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new SftpConnectionException('Could not connect to ' . $this->address() . ': ' . $e->getMessage(), previous: $e);
        }

        if (!is_string($hostKey)) {
            throw new HostKeyVerificationException($this->address() . ' did not prove that it holds its host key.');
        }

        $fingerprint = self::fingerprint($hostKey);
        if ($this->verifyHostKey && !in_array($fingerprint, $this->hostKeyFingerprints, true)) {
            $sftp->disconnect();
            throw new HostKeyVerificationException(
                'The host key of ' . $this->address() . ' (' . $fingerprint . ') is not one of the configured fingerprints. '
                . 'The server\'s key has changed, or this is not the real server. Nothing was sent.',
                $fingerprint,
            );
        }

        $credentials = array_values(array_filter([$this->privateKey, $this->password], static fn ($c): bool => $c !== null));
        try {
            $loggedIn = $sftp->login($this->username, ...$credentials);
        } catch (Throwable $e) {
            throw new SftpConnectionException('Could not log in to ' . $this->address() . ': ' . $e->getMessage(), previous: $e);
        }
        if (!$loggedIn) {
            $sftp->disconnect();
            throw new AuthenticationException('The server ' . $this->address() . ' rejected the credentials for ' . $this->username . '.');
        }

        // phpseclib caches file details by default. The connection outlives a request, so a cache would answer
        // later requests with files that have since changed or gone.
        $sftp->disableStatCache();

        return $this->sftp = $sftp;
    }

    private function failure(SFTP $sftp, string $message, string $path): SftpException
    {
        $error = $sftp->getLastSFTPError();
        $error = is_string($error) && $error !== '' ? $error : null;

        if ($error !== null && str_starts_with($error, 'NET_SFTP_STATUS_NO_SUCH_FILE')) {
            return new FileNotFoundException($message . ': no such file or directory', $path, $error);
        }

        return new SftpException($message . ($error !== null ? ': ' . $error : ''), $path, $error);
    }

    /**
     * The server path for a client path: inside the root when there is one.
     */
    private function resolve(string $path): string
    {
        $relative = self::normalise($path);

        if ($this->root === null) {
            $absolute = str_starts_with($path, '/');

            return ($absolute ? '/' : '') . ($relative === '' ? ($absolute ? '' : '.') : $relative);
        }

        if (str_starts_with($relative, '..')) {
            throw new InvalidArgumentException('The path "' . $path . '" leads outside the connection\'s root.');
        }

        return rtrim($this->root, '/') . ($relative === '' ? '' : '/' . $relative) ?: '/';
    }

    /**
     * Removes empty and "." segments and resolves ".." segments. Leading ".." segments that cannot be resolved are
     * kept, for resolve() to reject under a root.
     */
    private static function normalise(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * A path as FileInfo reports it: normalised, and absolute only when there is no root and it was given absolute.
     */
    private function clientPath(string $path): string
    {
        $relative = self::normalise($path);

        return $this->root === null && str_starts_with($path, '/') ? '/' . $relative : $relative;
    }

    private function address(): string
    {
        return 'sftp://' . $this->username . '@' . $this->host . ($this->port === 22 ? '' : ':' . $this->port);
    }
}
