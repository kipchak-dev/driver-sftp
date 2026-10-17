<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Library\Connection;

use phpseclib3\Net\SFTP;

/**
 * phpseclib 3, which reports a refused file operation by returning false.
 */
final class Phpseclib3Connection implements Connection
{
    private readonly SFTP $sftp;

    public function __construct(string $host, int $port, int $timeout)
    {
        $this->sftp = new SFTP($host, $port, $timeout);
        $this->sftp->setTimeout($timeout);
    }

    public function hostKey(): ?string
    {
        $key = $this->sftp->getServerPublicHostKey();

        return is_string($key) ? $key : null;
    }

    public function login(string $username, string|object ...$credentials): bool
    {
        if (!$this->sftp->login($username, ...$credentials)) {
            return false;
        }
        // The connection outlives a request, so cached file details would go stale.
        $this->sftp->disableStatCache();

        return true;
    }

    public function isConnected(): bool
    {
        return $this->sftp->isConnected();
    }

    public function disconnect(): void
    {
        $this->sftp->disconnect();
    }

    public function rawlist(string $directory): ?array
    {
        $entries = $this->sftp->rawlist($directory);

        return is_array($entries) ? $entries : null;
    }

    public function stat(string $path): ?array
    {
        $attributes = $this->sftp->stat($path);

        return is_array($attributes) ? $attributes : null;
    }

    public function isDirectory(string $path): bool
    {
        return $this->sftp->is_dir($path);
    }

    public function read(string $path): ?string
    {
        $contents = $this->sftp->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function download(string $path, mixed $localFile): bool
    {
        return $this->sftp->get($path, $localFile) !== false;
    }

    public function put(string $path, mixed $data, bool $fromLocalFile = false): bool
    {
        return $this->sftp->put($path, $data, $fromLocalFile ? SFTP::SOURCE_LOCAL_FILE : SFTP::SOURCE_STRING);
    }

    public function delete(string $path, bool $recursive): bool
    {
        return $this->sftp->delete($path, $recursive);
    }

    public function rename(string $from, string $to): bool
    {
        return $this->sftp->rename($from, $to);
    }

    public function posixRename(string $from, string $to): bool
    {
        return $this->sftp->posix_rename($from, $to);
    }

    public function supportsPosixRename(): bool
    {
        $extensions = $this->sftp->getSupportedExtensions();

        return is_array($extensions) && ($extensions['posix-rename@openssh.com'] ?? null) === '1';
    }

    public function createDirectory(string $path): bool
    {
        return $this->sftp->mkdir($path, -1, true);
    }

    public function lastError(): ?string
    {
        // phpseclib 3 names statuses NET_SFTP_STATUS_*; the protocol's own names are SSH_FX_*.
        $error = $this->sftp->getLastSFTPError();

        return is_string($error) && $error !== '' ? preg_replace('/^NET_SFTP_STATUS_/', 'SSH_FX_', $error) : null;
    }
}
