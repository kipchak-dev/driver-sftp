<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Library\Connection;

/**
 * The SFTP operations SftpClient uses, the same whichever major version of phpseclib is installed.
 *
 * A file operation the server refuses returns false (or null) and leaves the server's status in lastError(), named
 * as in the SFTP protocol: "SSH_FX_NO_SUCH_FILE: No such file". A lost or failed connection throws.
 */
interface Connection
{
    /**
     * Connects if need be, and returns the server's host key ("ssh-ed25519 AAAAC3Nza..."), or null when the server
     * did not prove that it holds it.
     */
    public function hostKey(): ?string;

    /**
     * @param string|object ...$credentials A password and/or a phpseclib private key, tried in order.
     */
    public function login(string $username, string|object ...$credentials): bool;

    public function isConnected(): bool;

    public function disconnect(): void;

    /**
     * @return array<string, array<string, mixed>>|null Attributes by name, including "." and "..".
     */
    public function rawlist(string $directory): ?array;

    /**
     * @return array<string, mixed>|null size, mtime, mode and type, following symbolic links.
     */
    public function stat(string $path): ?array;

    public function isDirectory(string $path): bool;

    public function read(string $path): ?string;

    /**
     * @param string|resource $localFile A local path, or a stream to write into.
     */
    public function download(string $path, mixed $localFile): bool;

    /**
     * @param string|resource $data The contents, a local path ($fromLocalFile) or a stream read from its position.
     */
    public function put(string $path, mixed $data, bool $fromLocalFile = false): bool;

    public function delete(string $path, bool $recursive): bool;

    public function rename(string $from, string $to): bool;

    /**
     * Renames, replacing any file at $to atomically. Only for servers where supportsPosixRename() is true.
     */
    public function posixRename(string $from, string $to): bool;

    public function supportsPosixRename(): bool;

    /**
     * Creates a directory and any missing parents.
     */
    public function createDirectory(string $path): bool;

    public function lastError(): ?string;
}
