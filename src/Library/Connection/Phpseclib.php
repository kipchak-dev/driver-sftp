<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Library\Connection;

use InvalidArgumentException;
use Throwable;

/**
 * Works with whichever major version of phpseclib Composer installed: 4, or 3 when another package (such as
 * league/flysystem-sftp-v3) still needs it.
 */
final class Phpseclib
{
    public static function version(): int
    {
        return class_exists(\phpseclib4\Net\SFTP::class) ? 4 : 3;
    }

    public static function connect(string $host, int $port, int $timeout): Connection
    {
        return self::version() === 4
            ? new Phpseclib4Connection($host, $port, $timeout)
            : new Phpseclib3Connection($host, $port, $timeout);
    }

    /**
     * Loads an OpenSSH, PuTTY or PEM private key, decrypting it with $passphrase if need be.
     *
     * @throws InvalidArgumentException when it cannot be loaded, or is a public key.
     */
    public static function loadPrivateKey(string $key, ?string $passphrase): object
    {
        try {
            $loaded = self::version() === 4
                ? \phpseclib4\Crypt\PublicKeyLoader::load($key, $passphrase)
                : \phpseclib3\Crypt\PublicKeyLoader::load($key, $passphrase ?? false);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'the private key could not be loaded. Check that it is a private key and that private_key_passphrase is right.',
                0,
                $e,
            );
        }

        if (!$loaded instanceof \phpseclib3\Crypt\Common\PrivateKey && !$loaded instanceof \phpseclib4\Crypt\Common\PrivateKey) {
            throw new InvalidArgumentException('private_key holds a public key, not a private key.');
        }

        return $loaded;
    }
}
