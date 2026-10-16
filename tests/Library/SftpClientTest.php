<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Tests\Library;

use InvalidArgumentException;
use Kipchak\Driver\Sftp\Exception\AuthenticationException;
use Kipchak\Driver\Sftp\Exception\FileNotFoundException;
use Kipchak\Driver\Sftp\Exception\HostKeyVerificationException;
use Kipchak\Driver\Sftp\Exception\SftpConnectionException;
use Kipchak\Driver\Sftp\Exception\SftpException;
use Kipchak\Driver\Sftp\Library\SftpClient;
use Kipchak\Driver\Sftp\Tests\Fake\FakeSftp;
use PHPUnit\Framework\TestCase;

final class SftpClientTest extends TestCase
{
    // ssh-keygen -lf of the fake server's host key.
    private const FINGERPRINT = 'SHA256:e1LFZ/jFM7OU2Kvzaqrn5Mx5iU/9XY/+bUQTx+B32QU';

    /** @var list<FakeSftp> */
    private array $connections = [];

    /** @var ?callable(FakeSftp): void Runs on every connection opened after it is set. */
    private $onConnect = null;

    private function client(?string $root = '/data', bool $atomicWrites = true, ?callable $prepare = null): SftpClient
    {
        return new SftpClient(
            host: 'sftp.example.com',
            username: 'kipchak',
            password: 'secret',
            hostKeyFingerprints: [self::FINGERPRINT],
            root: $root,
            atomicWrites: $atomicWrites,
            connector: function () use ($prepare): FakeSftp {
                $sftp = new FakeSftp();
                $sftp->directories['/data'] = true;
                $sftp->directories['/data/inbound'] = true;
                if ($prepare !== null) {
                    $prepare($sftp);
                }
                if ($this->onConnect !== null) {
                    ($this->onConnect)($sftp);
                }

                return $this->connections[] = $sftp;
            },
        );
    }

    // -------------------------------------------------------------------------
    // Connecting
    // -------------------------------------------------------------------------

    public function testFingerprintMatchesOpenSsh(): void
    {
        $this->assertSame(self::FINGERPRINT, SftpClient::fingerprint((new FakeSftp())->hostKey));
    }

    public function testConnectsChecksTheHostKeyThenLogsInOnce(): void
    {
        $client = $this->client();
        $client->exists('a.txt');
        $client->exists('b.txt');

        $this->assertCount(1, $this->connections, 'The connection is kept between calls');
        $this->assertSame(['hostkey', 'login kipchak 1', 'disableStatCache'], array_slice($this->connections[0]->calls, 0, 3));
    }

    public function testUnknownHostKeyIsRefusedBeforeAnyCredentialsAreSent(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->hostKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
        });

        try {
            $client->exists('a.txt');
            $this->fail('Expected a host key verification failure');
        } catch (HostKeyVerificationException $e) {
            $this->assertStringStartsWith('SHA256:', (string) $e->fingerprint);
            $this->assertNotSame(self::FINGERPRINT, $e->fingerprint);
        }
        $this->assertSame(['hostkey'], $this->connections[0]->calls);
    }

    public function testHostKeyCheckCanBeTurnedOff(): void
    {
        $sftp = new FakeSftp('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl');
        $client = new SftpClient(host: 'h', username: 'u', password: 'p', verifyHostKey: false, connector: static fn () => $sftp);

        $this->assertTrue($client->exists('/'));
    }

    public function testRejectedCredentials(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->acceptLogin = false;
        });

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('rejected the credentials for kipchak');
        $client->exists('a.txt');
    }

    public function testCredentialsAndFingerprintsAreRequired(): void
    {
        try {
            new SftpClient(host: 'h', username: 'u', hostKeyFingerprints: [self::FINGERPRINT]);
            $this->fail('Expected missing credentials to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('password, a private key', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host key fingerprints');
        new SftpClient(host: 'h', username: 'u', password: 'p');
    }

    // -------------------------------------------------------------------------
    // Reconnecting
    // -------------------------------------------------------------------------

    public function testAConnectionClosedWhileIdleIsReopenedAndTheOperationRepeated(): void
    {
        $client = $this->client();
        $client->write('inbound/a.csv', 'a');
        $this->connections[0]->dropOnNextOperation = true;

        // The second connection is to a fresh fake server: give it the file.
        $this->onConnect = static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/a.csv'] = 'a';
        };

        $this->assertSame('a', $client->read('inbound/a.csv'));
        $this->assertCount(2, $this->connections);
    }

    public function testAFailureOnAFreshConnectionIsNotRepeated(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->dropOnNextOperation = true;
        });

        $this->expectException(SftpConnectionException::class);
        $this->expectExceptionMessage('Lost the connection to sftp://kipchak@sftp.example.com: Connection closed by server');
        try {
            $client->read('inbound/a.csv');
        } finally {
            $this->assertCount(1, $this->connections);
        }
    }

    public function testAConnectionThatDiesTwiceIsReported(): void
    {
        $client = $this->client();
        $client->exists('a');
        $this->connections[0]->dropOnNextOperation = true;

        $this->onConnect = static function (FakeSftp $sftp): void {
            $sftp->dropOnNextOperation = true;
        };

        $this->expectException(SftpConnectionException::class);
        try {
            $client->exists('a');
        } finally {
            $this->assertCount(2, $this->connections, 'One reconnection, no more');
        }
    }

    public function testOperationErrorsAreNotRepeated(): void
    {
        $client = $this->client();
        $client->exists('a');

        try {
            $client->read('inbound/missing.csv');
            $this->fail('Expected a missing file');
        } catch (FileNotFoundException $e) {
            $this->assertSame('inbound/missing.csv', $e->path);
            $this->assertStringStartsWith('NET_SFTP_STATUS_NO_SUCH_FILE', (string) $e->sftpError);
        }
        $this->assertCount(1, $this->connections);
        $this->assertSame(1, count(array_filter($this->connections[0]->calls, static fn ($c) => str_starts_with($c, 'get '))));
    }

    // -------------------------------------------------------------------------
    // Paths
    // -------------------------------------------------------------------------

    public function testPathsAreResolvedInsideTheRoot(): void
    {
        $client = $this->client();
        $client->write('/inbound/./a.csv', 'a');
        $client->write('inbound//sub/../b.csv', 'b');

        $this->assertSame(['/data/inbound/a.csv', '/data/inbound/b.csv'], array_keys($this->connections[0]->files));
    }

    public function testPathsCannotClimbOutOfTheRoot(): void
    {
        $client = $this->client();

        foreach (['../etc/passwd', '/../etc/passwd', 'inbound/../../etc/passwd', '..'] as $path) {
            try {
                $client->read($path);
                $this->fail($path . ' should have been rejected');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('outside the connection\'s root', $e->getMessage());
            }
        }
        $this->assertSame([], $this->connections, 'Rejected before connecting');
    }

    public function testWithoutARootPathsAreUsedAsGiven(): void
    {
        $client = $this->client(root: null);
        $client->exists('/data/inbound/x');
        $client->exists('relative/../file');
        $client->exists('../up');
        $client->exists('');

        $this->assertSame(
            ['stat /data/inbound/x', 'stat file', 'stat ../up', 'stat .'],
            array_slice($this->connections[0]->calls, 3),
        );
    }

    public function testTheRootItselfCannotBeDeleted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->deleteDirectory('/');
    }

    // -------------------------------------------------------------------------
    // Files
    // -------------------------------------------------------------------------

    public function testWritesGoToATemporaryFileThatIsRenamedIntoPlace(): void
    {
        $client = $this->client();
        $client->write('inbound/orders.csv', 'id,total');

        $calls = $this->connections[0]->calls;
        $this->assertMatchesRegularExpression('#^put /data/inbound/\.orders\.csv\.[0-9a-f]{8}\.part$#', $calls[3]);
        $this->assertStringStartsWith('stat /data/inbound/.orders.csv.', $calls[4]);
        $this->assertStringStartsWith('posix_rename /data/inbound/.orders.csv.', $calls[5]);
        $this->assertStringEndsWith(' /data/inbound/orders.csv', $calls[5]);
        $this->assertSame(['/data/inbound/orders.csv' => 'id,total'], $this->connections[0]->files);
    }

    public function testWithoutPosixRenameAnExistingFileIsDeletedFirst(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->extensions = [];
            $sftp->files['/data/inbound/orders.csv'] = 'old';
        });
        $client->write('inbound/orders.csv', 'new');

        $this->assertSame(['/data/inbound/orders.csv' => 'new'], $this->connections[0]->files);
    }

    public function testNonAtomicWritesGoStraightToTheFile(): void
    {
        $client = $this->client(atomicWrites: false);
        $client->write('inbound/orders.csv', 'x');

        $this->assertSame('put /data/inbound/orders.csv', $this->connections[0]->calls[3]);
    }

    public function testAFailedWriteLeavesNoTemporaryFile(): void
    {
        $client = $this->client();

        try {
            $client->write('missing-directory/orders.csv', 'x');
            $this->fail('Expected the write to fail');
        } catch (FileNotFoundException) {
        }
        $this->assertSame([], $this->connections[0]->files);
    }

    public function testUploadAndWriteStream(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'sftp');
        file_put_contents($local, 'from disk');
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'skip|from stream');
        fseek($stream, 5);

        $client = $this->client();
        $client->upload($local, 'inbound/disk.txt');
        $client->writeStream('inbound/stream.txt', $stream);
        unlink($local);

        $this->assertSame('from disk', $this->connections[0]->files['/data/inbound/disk.txt']);
        $this->assertSame('from stream', $this->connections[0]->files['/data/inbound/stream.txt']);
    }

    public function testUploadOfAMissingLocalFile(): void
    {
        $this->expectException(SftpException::class);
        $this->expectExceptionMessage('not a readable file');
        $this->client()->upload('/nonexistent/file', 'inbound/x');
    }

    public function testListingIsSortedAndFiltered(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/b.csv'] = 'bb';
            $sftp->files['/data/inbound/a.csv'] = 'a';
            $sftp->files['/data/inbound/c.txt'] = 'c';
            $sftp->directories['/data/inbound/archive'] = true;
        });

        $all = $client->list('inbound');
        $this->assertSame(['a.csv', 'archive', 'b.csv', 'c.txt'], array_map(static fn ($f) => $f->name, $all));
        $this->assertSame('inbound/a.csv', $all[0]->path);
        $this->assertTrue($all[1]->isDirectory());

        $csv = $client->files('/inbound/', '*.csv');
        $this->assertSame(['inbound/a.csv', 'inbound/b.csv'], array_map(static fn ($f) => $f->path, $csv));
        $this->assertSame(2, $csv[1]->size);
        $this->assertSame(0o644, $csv[1]->permissions);
        $this->assertSame(1760000000, $csv[1]->modified?->getTimestamp());
    }

    public function testListingAMissingDirectory(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->client()->list('nowhere');
    }

    public function testStatAndExists(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/a.csv'] = 'abc';
        });

        $this->assertNull($client->stat('inbound/missing'));
        $this->assertFalse($client->exists('inbound/missing'));
        $this->assertTrue($client->exists('inbound'));
        $this->assertSame(3, $client->stat('inbound/a.csv')?->size);
        $this->assertSame('inbound/a.csv', $client->stat('/inbound/a.csv')?->path);
    }

    public function testReadDownloadAndReadStream(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/a.csv'] = 'contents';
        });
        $local = sys_get_temp_dir() . '/kipchak-sftp-' . bin2hex(random_bytes(4));

        $this->assertSame('contents', $client->read('inbound/a.csv'));
        $client->download('inbound/a.csv', $local);
        $this->assertSame('contents', file_get_contents($local));
        $this->assertFileDoesNotExist($local . '.part');
        unlink($local);
        $this->assertSame('contents', stream_get_contents($client->readStream('inbound/a.csv')));
    }

    public function testAFailedDownloadLeavesNoLocalFile(): void
    {
        $local = sys_get_temp_dir() . '/kipchak-sftp-' . bin2hex(random_bytes(4));

        try {
            $this->client()->download('inbound/missing', $local);
            $this->fail('Expected a missing file');
        } catch (FileNotFoundException) {
        }
        $this->assertFileDoesNotExist($local);
        $this->assertFileDoesNotExist($local . '.part');
    }

    public function testMoveRefusesToReplaceUnlessAsked(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/a.csv'] = 'a';
            $sftp->files['/data/inbound/b.csv'] = 'b';
        });

        try {
            $client->move('inbound/a.csv', 'inbound/b.csv');
            $this->fail('Expected the move to be refused');
        } catch (SftpException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $client->move('inbound/a.csv', 'inbound/b.csv', overwrite: true);
        $this->assertSame(['/data/inbound/b.csv' => 'a'], $this->connections[0]->files);
    }

    public function testDirectories(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/a.csv'] = 'a';
        });

        $client->createDirectory('archive/2026/10');
        $this->assertTrue($client->stat('archive/2026')?->isDirectory());

        $client->deleteDirectory('inbound');
        $this->assertFalse($client->exists('inbound/a.csv'));

        $this->expectException(FileNotFoundException::class);
        $client->deleteDirectory('inbound');
    }

    public function testDelete(): void
    {
        $client = $this->client(prepare: static function (FakeSftp $sftp): void {
            $sftp->files['/data/inbound/a.csv'] = 'a';
        });

        $client->delete('inbound/a.csv');
        $this->assertFalse($client->exists('inbound/a.csv'));

        $this->expectException(FileNotFoundException::class);
        $client->delete('inbound/a.csv');
    }
}
