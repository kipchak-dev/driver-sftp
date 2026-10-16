<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Tests\Integration;

use DI\Container;
use InvalidArgumentException;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Sftp\Exception\AuthenticationException;
use Kipchak\Driver\Sftp\Exception\FileNotFoundException;
use Kipchak\Driver\Sftp\Exception\HostKeyVerificationException;
use Kipchak\Driver\Sftp\Exception\SftpConnectionException;
use Kipchak\Driver\Sftp\Exception\SftpException;
use Kipchak\Driver\Sftp\Library\SftpClient;
use Kipchak\Driver\Sftp\Sftp;
use PHPUnit\Framework\TestCase;

/**
 * Runs the driver against a real OpenSSH server. Start it with tests/Integration/start-sftp.sh; tests skip when
 * it is not running.
 */
final class SftpServerTest extends TestCase
{
    private const PORT = 52222;
    private const KEYS = __DIR__ . '/.keys';

    private SftpClient $sftp;

    protected function setUp(): void
    {
        $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 1);
        if ($socket === false || !is_file(self::KEYS . '/fingerprints.txt')) {
            $this->markTestSkipped('SFTP test server not running; start it with tests/Integration/start-sftp.sh');
        }
        fclose($socket);

        $this->sftp = $this->driver(['password' => 'kipchak-password', 'root' => '/upload']);
        $this->empty();
    }

    protected function tearDown(): void
    {
        if (isset($this->sftp)) {
            $this->empty();
        }
    }

    private function empty(): void
    {
        foreach ($this->sftp->list() as $entry) {
            $entry->isDirectory() ? $this->sftp->deleteDirectory($entry->path) : $this->sftp->delete($entry->path);
        }
    }

    // -------------------------------------------------------------------------
    // Connecting
    // -------------------------------------------------------------------------

    public function testPasswordKeyAndEncryptedKeyLogins(): void
    {
        $this->sftp->write('hello.txt', 'hello');

        $withKey = $this->driver(['private_key' => self::KEYS . '/id_ed25519', 'root' => '/upload']);
        $this->assertSame('hello', $withKey->read('hello.txt'));

        $withEncryptedKey = $this->driver([
            'private_key' => (string) file_get_contents(self::KEYS . '/id_rsa'),
            'private_key_passphrase' => 'kipchak-passphrase',
            'root' => '/upload',
        ]);
        $this->assertSame('hello', $withEncryptedKey->read('hello.txt'));
    }

    public function testUnknownHostKeyIsRefused(): void
    {
        $client = $this->driver(['password' => 'kipchak-password', 'host_key_fingerprints' => ['SHA256:' . str_repeat('A', 43)]]);

        try {
            $client->list();
            $this->fail('Expected the host key to be refused');
        } catch (HostKeyVerificationException $e) {
            $this->assertContains($e->fingerprint, $this->fingerprints());
        }
    }

    /**
     * The server presents only its preferred key (here ed25519), so a list must include it: listing every key the
     * server has, as ssh-keyscan reports them, keeps working if the server's preference changes.
     */
    public function testTheListMustIncludeTheKeyTheServerPresents(): void
    {
        [$ed25519, $rsa] = $this->fingerprints();

        $this->driver(['password' => 'kipchak-password', 'host_key_fingerprints' => ['SHA256:' . str_repeat('A', 43), $rsa, $ed25519]])->list();

        $this->expectException(HostKeyVerificationException::class);
        $this->driver(['password' => 'kipchak-password', 'host_key_fingerprints' => [$rsa]])->list();
    }

    public function testWrongPasswordAndUnknownKey(): void
    {
        try {
            $this->driver(['password' => 'wrong'])->list();
            $this->fail('Expected the password to be rejected');
        } catch (AuthenticationException) {
        }

        $this->expectException(AuthenticationException::class);
        $this->driver(['private_key' => \phpseclib3\Crypt\EC::createKey('Ed25519')->toString('OpenSSH')])->list();
    }

    public function testNothingListening(): void
    {
        $this->expectException(SftpConnectionException::class);
        $this->expectExceptionMessage('Could not connect to sftp://kipchak@127.0.0.1:1');
        $this->driver(['password' => 'kipchak-password', 'port' => 1, 'timeout' => 2])->list();
    }

    // -------------------------------------------------------------------------
    // Files
    // -------------------------------------------------------------------------

    public function testABatchExchange(): void
    {
        $this->sftp->createDirectory('inbound');
        $this->sftp->createDirectory('archive/2026-10');
        $this->sftp->write('inbound/orders-001.csv', "id,total\n1,10\n");
        $this->sftp->write('inbound/orders-002.csv', "id,total\n2,20\n");
        $this->sftp->write('inbound/readme.txt', 'not a batch file');

        $batch = $this->sftp->files('inbound', 'orders-*.csv');
        $this->assertSame(['inbound/orders-001.csv', 'inbound/orders-002.csv'], array_map(static fn ($f) => $f->path, $batch));
        $this->assertSame(14, $batch[0]->size);
        $this->assertEqualsWithDelta(time(), $batch[0]->modified?->getTimestamp(), 60);

        foreach ($batch as $file) {
            $this->assertStringStartsWith('id,total', $this->sftp->read($file->path));
            $this->sftp->move($file->path, 'archive/2026-10/' . $file->name);
        }

        $this->assertSame(['readme.txt'], array_map(static fn ($f) => $f->name, $this->sftp->list('inbound')));
        $this->assertCount(2, $this->sftp->files('archive/2026-10'));
    }

    public function testAtomicWritesLeaveNoTemporaryFiles(): void
    {
        $this->sftp->write('a.csv', 'one');
        $this->sftp->write('a.csv', 'two');

        $this->assertSame('two', $this->sftp->read('a.csv'));
        $this->assertSame(['a.csv'], array_map(static fn ($f) => $f->name, $this->sftp->list()));
    }

    public function testNonAtomicWrites(): void
    {
        $client = $this->driver(['password' => 'kipchak-password', 'root' => '/upload', 'atomic_writes' => false]);
        $client->write('a.csv', 'one');
        $client->write('a.csv', 'two');

        $this->assertSame('two', $client->read('a.csv'));
    }

    public function testLargeFilesStreamBothWays(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'sftp-up');
        $copy = sys_get_temp_dir() . '/kipchak-sftp-down-' . bin2hex(random_bytes(4));
        $handle = fopen($local, 'wb');
        for ($i = 0; $i < 20; $i++) {
            fwrite($handle, random_bytes(1024 * 1024));
        }
        fclose($handle);

        try {
            $this->sftp->upload($local, 'large.bin');
            $this->assertSame(20 * 1024 * 1024, $this->sftp->stat('large.bin')?->size);

            $this->sftp->download('large.bin', $copy);
            $this->assertSame(hash_file('sha256', $local), hash_file('sha256', $copy));

            $stream = $this->sftp->readStream('large.bin');
            $this->assertSame(hash_file('sha256', $local), hash('sha256', (string) stream_get_contents($stream)));
            rewind($stream);
            $this->sftp->writeStream('copy.bin', $stream);
            $this->assertSame(20 * 1024 * 1024, $this->sftp->stat('copy.bin')?->size);
        } finally {
            @unlink($local);
            @unlink($copy);
        }
    }

    public function testMoveAndOverwrite(): void
    {
        $this->sftp->write('a.csv', 'a');
        $this->sftp->write('b.csv', 'b');

        try {
            $this->sftp->move('a.csv', 'b.csv');
            $this->fail('Expected the move to be refused');
        } catch (SftpException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->sftp->move('a.csv', 'b.csv', overwrite: true);
        $this->assertSame('a', $this->sftp->read('b.csv'));
        $this->assertFalse($this->sftp->exists('a.csv'));
    }

    public function testMissingFiles(): void
    {
        $this->assertNull($this->sftp->stat('missing.csv'));

        foreach ([
            fn () => $this->sftp->read('missing.csv'),
            fn () => $this->sftp->delete('missing.csv'),
            fn () => $this->sftp->list('missing'),
            fn () => $this->sftp->move('missing.csv', 'other.csv'),
            fn () => $this->sftp->deleteDirectory('missing'),
            fn () => $this->sftp->write('missing/a.csv', 'x'),
        ] as $i => $operation) {
            try {
                $operation();
                $this->fail('Operation ' . $i . ' should have failed');
            } catch (FileNotFoundException) {
            }
        }
        $this->assertSame([], $this->sftp->list(), 'No temporary file left behind');
    }

    public function testPermissionDenied(): void
    {
        $client = $this->driver(['password' => 'kipchak-password']);

        try {
            $client->write('/not-writable.csv', 'x');
            $this->fail('Expected the chroot directory to be read-only');
        } catch (SftpException $e) {
            $this->assertNotInstanceOf(FileNotFoundException::class, $e);
            $this->assertStringContainsString('PERMISSION_DENIED', (string) $e->sftpError);
        }
    }

    public function testRootConfinement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sftp->read('../etc/passwd');
    }

    public function testWithoutARootPathsAreRelativeToTheLoginDirectory(): void
    {
        $this->sftp->write('a.csv', 'a');
        $client = $this->driver(['password' => 'kipchak-password']);

        $this->assertSame('a', $client->read('upload/a.csv'));
        $this->assertSame('a', $client->read('/upload/a.csv'));
        $this->assertSame('/upload/a.csv', $client->files('/upload')[0]->path);
        $this->assertSame('upload/a.csv', $client->files('upload')[0]->path);
    }

    // -------------------------------------------------------------------------
    // Long-lived connections
    // -------------------------------------------------------------------------

    /**
     * A FrankenPHP worker keeps the connection between requests. When the server goes away in between (restarted,
     * or closing idle connections) the next call reconnects instead of failing.
     */
    public function testReconnectsAfterTheServerRestarts(): void
    {
        $this->sftp->write('before.csv', 'before');

        exec('docker restart -t 0 kipchak-sftp 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $this->waitForServer();

        $this->assertSame('before', $this->sftp->read('before.csv'));
        $this->sftp->write('after.csv', 'after');
        $this->assertSame('after', $this->sftp->read('after.csv'));
    }

    public function testChangesByOthersAreSeenOnTheSameConnection(): void
    {
        $other = $this->driver(['password' => 'kipchak-password', 'root' => '/upload']);
        $this->assertFalse($this->sftp->exists('a.csv'));

        $other->write('a.csv', 'a');
        $this->assertTrue($this->sftp->exists('a.csv'), 'No stale stat cache');
        $other->delete('a.csv');
        $this->assertFalse($this->sftp->exists('a.csv'));
    }

    private function driver(array $connection): SftpClient
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.sftp' => ['connections' => ['default' => array_merge([
            'host' => '127.0.0.1',
            'port' => self::PORT,
            'username' => 'kipchak',
            'host_key_fingerprints' => $this->fingerprints(),
        ], $connection)]]]);
        Sftp::initialise($container);

        return Sftp::get();
    }

    /**
     * @return list<string>
     */
    private function fingerprints(): array
    {
        return file(self::KEYS . '/fingerprints.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    private function waitForServer(): void
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 1);
            if ($socket !== false) {
                stream_set_timeout($socket, 1);
                $banner = fgets($socket);
                fclose($socket);
                if (is_string($banner) && str_starts_with($banner, 'SSH-2.0')) {
                    return;
                }
            }
            usleep(200000);
        }
        $this->fail('The SFTP server did not come back');
    }
}
