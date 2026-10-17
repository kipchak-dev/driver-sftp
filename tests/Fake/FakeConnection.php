<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Tests\Fake;

use Kipchak\Driver\Sftp\Library\Connection\Connection;

/**
 * An in-memory SFTP server: files are a path => contents map, directories a set. It records every call, and can
 * be told to drop the connection before the next operation.
 */
class FakeConnection implements Connection
{
    /** @var array<string, string> */
    public array $files = [];
    /** @var array<string, true> */
    public array $directories = ['/' => true];
    /** @var list<string> */
    public array $calls = [];
    public bool $posixRename = true;
    public bool $connected = true;
    public bool $acceptLogin = true;
    public bool $dropOnNextOperation = false;
    private ?string $lastError = null;

    public function __construct(public string $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIN5YiuNovjnjrkdpmDQL2HzYAIfShIR8Ki+OzcZ3K+Lm')
    {
    }

    public function hostKey(): ?string
    {
        $this->calls[] = 'hostkey';

        return $this->key;
    }

    public function login(string $username, string|object ...$credentials): bool
    {
        $this->calls[] = 'login ' . $username . ' ' . count($credentials);

        return $this->acceptLogin;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function rawlist(string $directory): ?array
    {
        $this->operation('rawlist ' . $directory);
        if (!isset($this->directories[$directory])) {
            return $this->notFound();
        }

        $entries = ['.' => ['type' => 2], '..' => ['type' => 2]];
        foreach ($this->files as $path => $contents) {
            if (dirname($path) === $directory) {
                $entries[basename($path)] = ['type' => 1, 'size' => strlen($contents), 'mtime' => 1760000000, 'mode' => 0o100644];
            }
        }
        foreach (array_keys($this->directories) as $path) {
            if ($path !== $directory && dirname($path) === $directory) {
                $entries[basename($path)] = ['type' => 2, 'mode' => 0o040755];
            }
        }

        return $entries;
    }

    public function stat(string $path): ?array
    {
        $this->operation('stat ' . $path);
        if (isset($this->files[$path])) {
            return ['type' => 1, 'size' => strlen($this->files[$path]), 'mtime' => 1760000000, 'mode' => 0o100644];
        }
        if (isset($this->directories[$path])) {
            return ['type' => 2, 'mode' => 0o040755];
        }

        return $this->notFound();
    }

    public function isDirectory(string $path): bool
    {
        return isset($this->directories[$path]);
    }

    public function read(string $path): ?string
    {
        $this->operation('get ' . $path);

        return $this->files[$path] ?? $this->notFound();
    }

    public function download(string $path, mixed $localFile): bool
    {
        $this->operation('get ' . $path);
        if (!isset($this->files[$path])) {
            return $this->notFound() ?? false;
        }

        return is_resource($localFile)
            ? fwrite($localFile, $this->files[$path]) !== false
            : file_put_contents($localFile, $this->files[$path]) !== false;
    }

    public function put(string $path, mixed $data, bool $fromLocalFile = false): bool
    {
        $this->operation('put ' . $path);
        if (!isset($this->directories[dirname($path)])) {
            return $this->notFound() ?? false;
        }
        $this->files[$path] = match (true) {
            is_resource($data) => (string) stream_get_contents($data),
            $fromLocalFile => (string) file_get_contents($data),
            default => (string) $data,
        };

        return true;
    }

    public function delete(string $path, bool $recursive): bool
    {
        $this->operation('delete ' . $path);
        if (isset($this->files[$path])) {
            unset($this->files[$path]);

            return true;
        }
        if ($recursive && isset($this->directories[$path])) {
            foreach (array_keys($this->files) as $file) {
                if (str_starts_with($file, $path . '/')) {
                    unset($this->files[$file]);
                }
            }
            foreach (array_keys($this->directories) as $directory) {
                if ($directory === $path || str_starts_with($directory, $path . '/')) {
                    unset($this->directories[$directory]);
                }
            }

            return true;
        }

        return $this->notFound() ?? false;
    }

    public function rename(string $from, string $to): bool
    {
        $this->operation('rename ' . $from . ' ' . $to);
        if (isset($this->files[$to]) || !isset($this->files[$from])) {
            $this->lastError = 'SSH_FX_FAILURE: Failure';

            return false;
        }
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);

        return true;
    }

    public function posixRename(string $from, string $to): bool
    {
        $this->operation('posix_rename ' . $from . ' ' . $to);
        if (!isset($this->files[$from])) {
            return $this->notFound() ?? false;
        }
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);

        return true;
    }

    public function supportsPosixRename(): bool
    {
        return $this->posixRename;
    }

    public function createDirectory(string $path): bool
    {
        $this->operation('mkdir ' . $path);
        for ($directory = $path; $directory !== '/' && $directory !== '.'; $directory = dirname($directory)) {
            $this->directories[$directory] = true;
        }

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Records the call, and drops the connection the way a server closing an idle one does: the socket still
     * looks open until something is sent.
     */
    private function operation(string $call): void
    {
        $this->calls[] = $call;
        if ($this->dropOnNextOperation) {
            $this->dropOnNextOperation = false;
            $this->connected = false;
            throw new \RuntimeException('Connection closed by server');
        }
    }

    private function notFound(): null
    {
        $this->lastError = 'SSH_FX_NO_SUCH_FILE: No such file';

        return null;
    }
}
