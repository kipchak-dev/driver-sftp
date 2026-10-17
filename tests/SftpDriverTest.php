<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Tests;

use DI\Container;
use InvalidArgumentException;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Sftp\Library\SftpClient;
use Kipchak\Driver\Sftp\Sftp;
use PHPUnit\Framework\TestCase;

final class SftpDriverTest extends TestCase
{
    private const FINGERPRINT = 'SHA256:e1LFZ/jFM7OU2Kvzaqrn5Mx5iU/9XY/+bUQTx+B32QU';
    private const FIXTURES = __DIR__ . '/fixtures';

    private function primeDriver(array $config): Container
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.sftp' => $config]);
        Sftp::initialise($container);

        return $container;
    }

    private function connection(array $overrides = []): array
    {
        return array_merge([
            'host' => 'sftp.example.com',
            'username' => 'kipchak',
            'password' => 'secret',
            'host_key_fingerprints' => [self::FINGERPRINT],
        ], $overrides);
    }

    public function testRegistersOneContainerEntryPerConnection(): void
    {
        $container = $this->primeDriver(['connections' => [
            'default' => $this->connection(),
            'bank' => $this->connection(['host' => 'sftp.bank.example']),
        ]]);

        $this->assertTrue($container->has('drivers.filetransfer.sftp.default'));
        $this->assertTrue($container->has('drivers.filetransfer.sftp.bank'));
        $this->assertInstanceOf(SftpClient::class, Sftp::get());
        $this->assertSame('sftp.bank.example', $this->property(Sftp::get('bank'), 'host'));
        $this->assertSame(Sftp::get(), Sftp::get('default'), 'One client, and so one connection, per worker');
    }

    public function testNoConnectionsRegistersNothing(): void
    {
        $this->assertFalse($this->primeDriver([])->has('drivers.filetransfer.sftp.default'));
    }

    public function testCustomConfigFileName(): void
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['partners' => ['connections' => ['default' => $this->connection()]]]);
        Sftp::initialise($container, 'partners');

        $this->assertInstanceOf(SftpClient::class, Sftp::get());
    }

    public function testConfigIsMappedOntoTheClient(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'port' => '2222',
            'host_key_fingerprints' => ['SHA256:abc=', ' def ', ''],
            'root' => '/outbound',
            'timeout' => '5',
            'atomic_writes' => false,
        ])]]);

        $client = Sftp::get();

        $this->assertSame(2222, $this->property($client, 'port'));
        $this->assertSame('secret', $this->property($client, 'password'));
        $this->assertNull($this->property($client, 'privateKey'));
        $this->assertSame(['SHA256:abc', 'SHA256:def'], $this->property($client, 'hostKeyFingerprints'));
        $this->assertTrue($this->property($client, 'verifyHostKey'));
        $this->assertSame('/outbound', $this->property($client, 'root'));
        $this->assertSame(5, $this->property($client, 'timeout'));
        $this->assertFalse($this->property($client, 'atomicWrites'));
    }

    public function testDefaults(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection(['root' => ''])]]);
        $client = Sftp::get();

        $this->assertSame(22, $this->property($client, 'port'));
        $this->assertNull($this->property($client, 'root'));
        $this->assertSame(10, $this->property($client, 'timeout'));
        $this->assertTrue($this->property($client, 'atomicWrites'));
    }

    public function testFingerprintsCanBeOneCommaSeparatedString(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'host_key_fingerprints' => 'SHA256:one, SHA256:two',
        ])]]);

        $this->assertSame(['SHA256:one', 'SHA256:two'], $this->property(Sftp::get(), 'hostKeyFingerprints'));
    }

    public function testHostAndUsernameAreRequired(): void
    {
        foreach (['host', 'username'] as $key) {
            $this->primeDriver(['connections' => ['default' => $this->connection([$key => ''])]]);
            try {
                Sftp::get();
                $this->fail('Expected a missing ' . $key . ' to be rejected');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('SFTP connection "default" requires a ' . $key . '.', $e->getMessage());
            }
        }
    }

    public function testMissingFingerprintsAreRejectedUnlessVerificationIsOff(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection(['host_key_fingerprints' => []])]]);
        try {
            Sftp::get();
            $this->fail('Expected missing fingerprints to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('SFTP connection "default": ', $e->getMessage());
            $this->assertStringContainsString('verify_host_key', $e->getMessage());
        }

        $this->primeDriver(['connections' => ['default' => $this->connection(['host_key_fingerprints' => [], 'verify_host_key' => false])]]);
        $this->assertFalse($this->property(Sftp::get(), 'verifyHostKey'));
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection(['password' => ''])]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a password, a private key or both');
        Sftp::get();
    }

    public function testPrivateKeyFromAFileWithAPassphrase(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'password' => '',
            'private_key' => self::FIXTURES . '/id_ed25519_encrypted',
            'private_key_passphrase' => 'phrase',
        ])]]);

        $this->assertIsPrivateKey($this->property(Sftp::get(), 'privateKey'));
    }

    public function testPrivateKeyAsAString(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'private_key' => (string) file_get_contents(self::FIXTURES . '/id_ed25519'),
        ])]]);

        $client = Sftp::get();
        $this->assertIsPrivateKey($this->property($client, 'privateKey'));
        $this->assertSame('secret', $this->property($client, 'password'), 'Both, for servers that require both');
    }

    public function testWrongPassphrase(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'private_key' => self::FIXTURES . '/id_ed25519_encrypted',
            'private_key_passphrase' => 'wrong',
        ])]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SFTP connection "default": the private key could not be loaded');
        Sftp::get();
    }

    public function testMissingPassphrase(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'private_key' => self::FIXTURES . '/id_ed25519_encrypted',
        ])]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the private key could not be loaded');
        Sftp::get();
    }

    public function testMissingKeyFile(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection(['private_key' => '/nonexistent/id_ed25519'])]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot read the private key file /nonexistent/id_ed25519');
        Sftp::get();
    }

    public function testAPublicKeyIsNotAPrivateKey(): void
    {
        $this->primeDriver(['connections' => ['default' => $this->connection([
            'private_key' => (string) file_get_contents(self::FIXTURES . '/id_ed25519.pub'),
        ])]]);

        $this->expectException(InvalidArgumentException::class);
        Sftp::get();
    }

    public function testSampleConfigIsValid(): void
    {
        require_once dirname((string) (new \ReflectionClass(\Kipchak\Core\Api::class))->getFileName()) . '/Functions.php';
        putenv('SFTP_PASSWORD=from-the-environment');
        try {
            $this->primeDriver(require __DIR__ . '/../sample.config.php');
            $client = Sftp::get();
        } finally {
            putenv('SFTP_PASSWORD');
        }
        $this->assertSame('sftp.example.com', $this->property($client, 'host'));
        $this->assertNull($this->property($client, 'root'));
    }

    private function assertIsPrivateKey(mixed $key): void
    {
        $this->assertTrue(
            $key instanceof \phpseclib3\Crypt\Common\PrivateKey || $key instanceof \phpseclib4\Crypt\Common\PrivateKey,
            'Expected a phpseclib private key',
        );
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
