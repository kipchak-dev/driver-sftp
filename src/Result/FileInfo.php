<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Result;

use DateTimeImmutable;

/**
 * A remote file or directory, as listed or stat'ed on the server.
 *
 * $path is the path as given to the client, relative to the connection's root, so it can be passed straight back
 * to read(), move() or delete().
 */
final readonly class FileInfo
{
    public const string TYPE_FILE = 'file';
    public const string TYPE_DIRECTORY = 'directory';
    public const string TYPE_LINK = 'link';
    public const string TYPE_OTHER = 'other';

    public function __construct(
        public string $path,
        public string $name,
        public string $type,
        public ?int $size = null,
        public ?DateTimeImmutable $modified = null,
        public ?int $permissions = null,
    ) {
    }

    /**
     * Builds the result from phpseclib's attribute array: size, mtime, mode and type (SFTP's 1 regular file,
     * 2 directory, 3 symbolic link). Servers speaking SFTP version 3 may send no type, so it falls back to the
     * file type bits of the mode.
     */
    public static function fromAttributes(string $path, array $attributes): self
    {
        $name = basename($path);

        return new self(
            path: $path,
            name: $name === '' ? '/' : $name,
            type: self::type($attributes),
            size: isset($attributes['size']) ? (int) $attributes['size'] : null,
            modified: isset($attributes['mtime']) ? (new DateTimeImmutable())->setTimestamp((int) $attributes['mtime']) : null,
            permissions: isset($attributes['mode']) ? ((int) $attributes['mode']) & 0o7777 : null,
        );
    }

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    public function isDirectory(): bool
    {
        return $this->type === self::TYPE_DIRECTORY;
    }

    private static function type(array $attributes): string
    {
        if (isset($attributes['type'])) {
            return match ((int) $attributes['type']) {
                1 => self::TYPE_FILE,
                2 => self::TYPE_DIRECTORY,
                3 => self::TYPE_LINK,
                default => self::TYPE_OTHER,
            };
        }

        if (isset($attributes['mode'])) {
            return match (((int) $attributes['mode']) & 0o170000) {
                0o100000 => self::TYPE_FILE,
                0o040000 => self::TYPE_DIRECTORY,
                0o120000 => self::TYPE_LINK,
                default => self::TYPE_OTHER,
            };
        }

        return self::TYPE_OTHER;
    }
}
