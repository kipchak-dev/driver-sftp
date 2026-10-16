<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp;

use InvalidArgumentException;
use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Sftp\Library\SftpClient;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use Psr\Container\ContainerInterface;
use Throwable;

class Sftp implements Interfaces\Driver
{
    protected static ?ContainerInterface $container = null;

    public static function initialise(ContainerInterface $container, ?string $configFileName = null): void
    {
        self::$container = $container;
        self::set($configFileName);
    }

    public static function set(?string $configFileName = null): void
    {
        $configFileName = $configFileName ?? 'kipchak.sftp';
        $config = Config::get($configFileName);

        if (empty($config['connections'])) {
            return;
        }

        foreach ($config['connections'] as $connectionName => $connectionDetails) {
            self::$container->set(
                'drivers.filetransfer.sftp.' . $connectionName,
                function (ContainerInterface $c) use ($connectionName, $connectionDetails): SftpClient {
                    return self::createClient((string) $connectionName, $connectionDetails);
                }
            );
        }
    }

    public static function get(?string $configKey = null): SftpClient
    {
        return self::$container->get('drivers.filetransfer.sftp.' . ($configKey ?? 'default'));
    }

    protected static function createClient(string $connectionName, array $connectionDetails): SftpClient
    {
        foreach (['host', 'username'] as $key) {
            if (!is_string($connectionDetails[$key] ?? null) || $connectionDetails[$key] === '') {
                throw new InvalidArgumentException('SFTP connection "' . $connectionName . '" requires a ' . $key . '.');
            }
        }

        $fingerprints = $connectionDetails['host_key_fingerprints'] ?? [];
        $fingerprints = array_values(array_filter(
            array_map(self::normaliseFingerprint(...), is_array($fingerprints) ? $fingerprints : explode(',', (string) $fingerprints)),
            static fn (string $fingerprint): bool => $fingerprint !== '',
        ));

        $privateKey = self::privateKey($connectionName, $connectionDetails);

        try {
            return new SftpClient(
                host: $connectionDetails['host'],
                username: $connectionDetails['username'],
                password: ($connectionDetails['password'] ?? '') !== '' ? (string) $connectionDetails['password'] : null,
                privateKey: $privateKey,
                hostKeyFingerprints: $fingerprints,
                verifyHostKey: (bool) ($connectionDetails['verify_host_key'] ?? true),
                port: (int) ($connectionDetails['port'] ?? 22),
                root: ($connectionDetails['root'] ?? '') !== '' ? (string) $connectionDetails['root'] : null,
                timeout: (int) ($connectionDetails['timeout'] ?? 10),
                atomicWrites: (bool) ($connectionDetails['atomic_writes'] ?? true),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('SFTP connection "' . $connectionName . '": ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Loads private_key, which is either the path to a key file or the key itself (from a secret in an environment
     * variable). OpenSSH, PuTTY and PKCS#1/#8 keys are accepted, encrypted with private_key_passphrase if need be.
     */
    protected static function privateKey(string $connectionName, array $connectionDetails): ?PrivateKey
    {
        $key = (string) ($connectionDetails['private_key'] ?? '');
        if ($key === '') {
            return null;
        }

        if (!str_contains($key, "\n") && !str_contains($key, 'PRIVATE KEY')) {
            if (!is_file($key) || !is_readable($key)) {
                throw new InvalidArgumentException('SFTP connection "' . $connectionName . '": cannot read the private key file ' . $key . '.');
            }
            $key = (string) file_get_contents($key);
        }

        $passphrase = (string) ($connectionDetails['private_key_passphrase'] ?? '');
        try {
            $loaded = PublicKeyLoader::load($key, $passphrase === '' ? false : $passphrase);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'SFTP connection "' . $connectionName . '": the private key could not be loaded. Check that it is a private key and that private_key_passphrase is right.',
                0,
                $e,
            );
        }

        if (!$loaded instanceof PrivateKey) {
            throw new InvalidArgumentException('SFTP connection "' . $connectionName . '": private_key holds a public key, not a private key.');
        }

        return $loaded;
    }

    /**
     * Accepts fingerprints as ssh-keygen -l prints them, with or without the "SHA256:" prefix and base64 padding.
     */
    protected static function normaliseFingerprint(mixed $fingerprint): string
    {
        $fingerprint = trim((string) $fingerprint);
        if ($fingerprint === '') {
            return '';
        }
        if (str_starts_with($fingerprint, 'SHA256:')) {
            $fingerprint = substr($fingerprint, 7);
        }

        return 'SHA256:' . rtrim($fingerprint, '=');
    }
}
