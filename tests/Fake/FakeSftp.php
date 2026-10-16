<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Tests\Fake;

use phpseclib3\Net\SFTP;

/**
 * An in-memory stand-in for phpseclib's SFTP connection: files are a path => contents map, directories a set.
 * It records every call, and can be told to drop the connection before the next operation.
 */
class FakeSftp extends SFTP
{
    /** @var array<string, string> */
    public array $files = [];
    /** @var array<string, true> */
    public array $directories = ['/' => true];
    /** @var list<string> */
    public array $calls = [];
    /** @var array<string, string> */
    public array $extensions = ['posix-rename@openssh.com' => '1'];
    public bool $connected = true;
    public bool $acceptLogin = true;
    public bool $dropOnNextOperation = false;
    public ?string $lastError = null;

    public function __construct(public string $hostKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIN5YiuNovjnjrkdpmDQL2HzYAIfShIR8Ki+OzcZ3K+Lm')
    {
        // No parent constructor: nothing here touches the network.
    }

    public function getServerPublicHostKey()
    {
        $this->calls[] = 'hostkey';

        return $this->hostKey;
    }

    public function login($username, ...$args)
    {
        $this->calls[] = 'login ' . $username . ' ' . count($args);

        return $this->acceptLogin;
    }

    public function setTimeout($timeout)
    {
    }

    public function disableStatCache()
    {
        $this->calls[] = 'disableStatCache';
    }

    public function isConnected($level = 0)
    {
        return $this->connected;
    }

    public function disconnect()
    {
        $this->connected = false;
    }

    public function getSupportedExtensions()
    {
        return $this->extensions;
    }

    public function getLastSFTPError()
    {
        return $this->lastError ?? '';
    }

    public function rawlist($dir = '.', $recursive = false)
    {
        $this->operation('rawlist ' . $dir);
        if (!isset($this->directories[$dir])) {
            return $this->notFound();
        }

        $entries = ['.' => ['type' => 2], '..' => ['type' => 2]];
        foreach ($this->files as $path => $contents) {
            if (dirname($path) === $dir) {
                $entries[basename($path)] = ['type' => 1, 'size' => strlen($contents), 'mtime' => 1760000000, 'mode' => 0o100644];
            }
        }
        foreach (array_keys($this->directories) as $path) {
            if ($path !== $dir && dirname($path) === $dir) {
                $entries[basename($path)] = ['type' => 2, 'mode' => 0o040755];
            }
        }

        return $entries;
    }

    public function stat($filename)
    {
        $this->operation('stat ' . $filename);
        if (isset($this->files[$filename])) {
            return ['type' => 1, 'size' => strlen($this->files[$filename]), 'mtime' => 1760000000, 'mode' => 0o100644];
        }
        if (isset($this->directories[$filename])) {
            return ['type' => 2, 'mode' => 0o040755];
        }

        return $this->notFound();
    }

    public function is_dir($path)
    {
        return isset($this->directories[$path]);
    }

    public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
    {
        $this->operation('get ' . $remote_file);
        if (!isset($this->files[$remote_file])) {
            return $this->notFound();
        }
        if (is_resource($local_file)) {
            fwrite($local_file, $this->files[$remote_file]);

            return true;
        }
        if (is_string($local_file)) {
            return file_put_contents($local_file, $this->files[$remote_file]) !== false;
        }

        return $this->files[$remote_file];
    }

    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $this->operation('put ' . $remote_file);
        if (!isset($this->directories[dirname($remote_file)])) {
            return $this->notFound();
        }
        $this->files[$remote_file] = match (true) {
            is_resource($data) => (string) stream_get_contents($data),
            ($mode & self::SOURCE_LOCAL_FILE) !== 0 => (string) file_get_contents($data),
            default => (string) $data,
        };

        return true;
    }

    public function delete($path, $recursive = true)
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

        return $this->notFound();
    }

    public function rename($oldname, $newname)
    {
        $this->operation('rename ' . $oldname . ' ' . $newname);
        if (isset($this->files[$newname]) || !isset($this->files[$oldname])) {
            $this->lastError = 'NET_SFTP_STATUS_FAILURE: Failure';

            return false;
        }
        $this->files[$newname] = $this->files[$oldname];
        unset($this->files[$oldname]);

        return true;
    }

    public function posix_rename($oldname, $newname)
    {
        $this->operation('posix_rename ' . $oldname . ' ' . $newname);
        if (!isset($this->files[$oldname])) {
            return $this->notFound();
        }
        $this->files[$newname] = $this->files[$oldname];
        unset($this->files[$oldname]);

        return true;
    }

    public function mkdir($dir, $mode = -1, $recursive = false)
    {
        $this->operation('mkdir ' . $dir);
        for ($path = $dir; $path !== '/' && $path !== '.'; $path = dirname($path)) {
            $this->directories[$path] = true;
        }

        return true;
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

    private function notFound(): bool
    {
        $this->lastError = 'NET_SFTP_STATUS_NO_SUCH_FILE: No such file';

        return false;
    }
}
