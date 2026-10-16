<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Tests\Result;

use Kipchak\Driver\Sftp\Result\FileInfo;
use PHPUnit\Framework\TestCase;

final class FileInfoTest extends TestCase
{
    public function testFromAttributes(): void
    {
        $file = FileInfo::fromAttributes('inbound/a.csv', ['type' => 1, 'size' => 12, 'mtime' => 1760000000, 'mode' => 0o100640]);

        $this->assertSame('a.csv', $file->name);
        $this->assertSame('inbound/a.csv', $file->path);
        $this->assertTrue($file->isFile());
        $this->assertFalse($file->isDirectory());
        $this->assertSame(12, $file->size);
        $this->assertSame(0o640, $file->permissions);
        $this->assertSame('2025-10-09T08:53:20+00:00', $file->modified?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testTypeFromTheModeWhenTheServerSendsNone(): void
    {
        $this->assertSame(FileInfo::TYPE_DIRECTORY, FileInfo::fromAttributes('d', ['mode' => 0o040755])->type);
        $this->assertSame(FileInfo::TYPE_FILE, FileInfo::fromAttributes('f', ['mode' => 0o100644])->type);
        $this->assertSame(FileInfo::TYPE_LINK, FileInfo::fromAttributes('l', ['mode' => 0o120777])->type);
        $this->assertSame(FileInfo::TYPE_OTHER, FileInfo::fromAttributes('s', ['mode' => 0o140755])->type);
        $this->assertSame(FileInfo::TYPE_OTHER, FileInfo::fromAttributes('x', [])->type);
    }

    public function testTypeFieldWins(): void
    {
        $this->assertSame(FileInfo::TYPE_LINK, FileInfo::fromAttributes('l', ['type' => 3, 'mode' => 0o100644])->type);
        $this->assertSame(FileInfo::TYPE_OTHER, FileInfo::fromAttributes('o', ['type' => 5])->type);
    }

    public function testMissingAttributesAreNull(): void
    {
        $file = FileInfo::fromAttributes('', ['type' => 2]);

        $this->assertSame('/', $file->name);
        $this->assertNull($file->size);
        $this->assertNull($file->modified);
        $this->assertNull($file->permissions);
    }
}
