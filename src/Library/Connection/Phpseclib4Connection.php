<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Library\Connection;

use Closure;
use phpseclib4\Exception\FileSystemException;
use phpseclib4\Net\SFTP;
use phpseclib4\Net\SFTP\StatusCode;

/**
 * phpseclib 4, which reports a refused file operation by throwing a FileSystemException carrying the SFTP status.
 * The exception is turned into a false return and lastError(), so that both versions behave alike. Every other exception, such as a lost connection, is left to propagate.
 */
final class Phpseclib4Connection implements Connection
{
    private readonly SFTP $sftp;
    private ?string $lastError = null;

    public function __construct(string $host, int $port, int $timeout)
    {
        $this->sftp = new SFTP($host, $port, $timeout);
        $this->sftp->setTimeout($timeout);
    }

    public function hostKey(): ?string
    {
        return $this->sftp->getServerPublicHostKey();
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
        return $this->attempt(fn (): array => $this->sftp->rawlist($directory));
    }

    public function stat(string $path): ?array
    {
        return $this->attempt(fn (): array => $this->sftp->stat($path));
    }

    public function isDirectory(string $path): bool
    {
        return $this->sftp->is_dir($path);
    }

    public function read(string $path): ?string
    {
        return $this->attempt(fn (): ?string => $this->sftp->get($path));
    }

    public function download(string $path, mixed $localFile): bool
    {
        return $this->succeeds(fn () => $this->sftp->get($path, $localFile));
    }

    public function put(string $path, mixed $data, bool $fromLocalFile = false): bool
    {
        return $this->succeeds(fn () => $this->sftp->put($path, $data, $fromLocalFile ? SFTP::SOURCE_LOCAL_FILE : SFTP::SOURCE_STRING));
    }

    public function delete(string $path, bool $recursive): bool
    {
        return $this->succeeds(fn () => $this->sftp->delete($path, $recursive));
    }

    public function rename(string $from, string $to): bool
    {
        return $this->succeeds(fn () => $this->sftp->rename($from, $to));
    }

    public function posixRename(string $from, string $to): bool
    {
        return $this->succeeds(fn () => $this->sftp->posix_rename($from, $to));
    }

    public function supportsPosixRename(): bool
    {
        return ($this->sftp->getSupportedExtensions()['posix-rename@openssh.com'] ?? null) === '1';
    }

    public function createDirectory(string $path): bool
    {
        return $this->succeeds(fn () => $this->sftp->mkdir($path, -1, true));
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function succeeds(Closure $operation): bool
    {
        return $this->attempt(static function () use ($operation): bool {
            $operation();

            return true;
        }) ?? false;
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T|null
     */
    private function attempt(Closure $operation): mixed
    {
        $this->lastError = null;

        try {
            return $operation();
        } catch (FileSystemException $e) {
            $this->lastError = self::describe($e);

            return null;
        }
    }

    /**
     * "Error from server (SSH_FX_NO_SUCH_FILE): No such file" becomes "SSH_FX_NO_SUCH_FILE: No such file".
     */
    private static function describe(FileSystemException $e): string
    {
        $message = $e->getMessage();
        $detail = str_contains($message, '): ') ? substr($message, strpos($message, '): ') + 3) : $message;

        if ($e->getCode() <= 0) {
            return $detail;
        }

        try {
            $status = StatusCode::getConstantNameByValue((int) $e->getCode());
        } catch (\Throwable) {
            return $detail;
        }

        return 'SSH_FX_' . $status . ': ' . $detail;
    }
}
