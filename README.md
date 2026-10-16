# SFTP Driver for Kipchak

An SFTP driver for Kipchak: exchange files with partners, banks, suppliers and other systems that still trade in
batch files over SFTP.

* **Batch exchange**: list a directory with a wildcard, read or download each file, then move it to an archive.
* **No partial files**: writes go to a temporary file that is renamed into place once complete, so a program
  collecting files from the directory never picks up half of one.
* **Large files**: uploads and downloads stream to and from disk rather than through memory.
* **Host key pinning**: the connection is refused, before any credentials are sent, unless the server's host key
  matches a configured fingerprint.
* **Confined paths**: with a root directory, paths that climb out of it are rejected, so a file name taken from a
  request cannot reach the rest of the server.
* **Worker mode**: the connection is kept between requests and re-opened when the server has closed it.

Built on [phpseclib](https://phpseclib.com) 3, which is pure PHP: it needs neither the `ssh2` extension nor an
`ssh` program, so it runs on the hardened Kipchak runtime image. Tested against OpenSSH 10.3.

#### Composer Package

`kipchak/driver-sftp`

## Installation

```bash
composer require kipchak/driver-sftp
```

Initialise it in `drivers/drivers.php`, after the Config driver:

```php
use Kipchak\Driver\Sftp\Sftp;

Sftp::initialise($container);
```

## Configuration

Copy [`sample.config.php`](sample.config.php) to `config/kipchak.sftp.php`:

```php
return [
    'connections' => [
        'default' => [
            'host' => env('SFTP_HOST', 'sftp.example.com'),
            'username' => env('SFTP_USERNAME', 'kipchak'),
            'private_key' => env('SFTP_PRIVATE_KEY', ''),
            'host_key_fingerprints' => [
                'SHA256:e1LFZ/jFM7OU2Kvzaqrn5Mx5iU/9XY/+bUQTx+B32QU',
            ],
            'root' => '/outbound',
        ],
    ],
];
```

| Key | Default | Meaning |
|---|---|---|
| `host` | required | The server's host name or IP address |
| `port` | `22` | |
| `username` | required | |
| `password` | none | A password. Give a password, a private key, or both for servers that require both. |
| `private_key` | none | The path to a private key file, or the key itself. OpenSSH, PuTTY and PEM keys are accepted. |
| `private_key_passphrase` | none | The passphrase of an encrypted private key |
| `host_key_fingerprints` | required | The server's SHA256 host key fingerprints, as a list or a comma-separated string |
| `verify_host_key` | `true` | Set `false` only for local development: any server is then accepted |
| `root` | none | Confines every path to this directory. Without it, paths are used as given and relative ones start from the login directory. |
| `timeout` | `10` | Seconds to connect and to wait for each response |
| `atomic_writes` | `true` | Write to a temporary file and rename it into place. Turn off only for servers that do not allow renaming. |

Add more entries under `connections` for more servers, and fetch them by name: `Sftp::get('bank')`.

### Host key fingerprints

The fingerprint is how the driver knows it is talking to the real server and not to something in between. Get
the server's fingerprints from someone who runs it, or with:

```bash
ssh-keyscan -p 22 sftp.example.com | ssh-keygen -lf -
```

```text
256 SHA256:e1LFZ/jFM7OU2Kvzaqrn5Mx5iU/9XY/+bUQTx+B32QU sftp.example.com (ED25519)
3072 SHA256:lpcYaFf8MVEHUxFDUHvaei2bEJTAp5GitJLdwGSW28c sftp.example.com (RSA)
```

List every fingerprint it prints. The server presents only the key type it prefers, and listing all of them keeps
the connection working if that preference changes. When the server's key is not listed, the driver throws a
`HostKeyVerificationException` that includes the fingerprint it was given, and sends nothing.

`ssh-keyscan` itself does not verify anything: run it from a network you trust, and compare the result with the
fingerprints the server's operator publishes where you can.

### Private keys from secrets

`private_key` accepts the key's contents as well as a path, so the key can come from a Kubernetes secret or a
vault through an environment variable, without being written to disk.

An encrypted OpenSSH key takes about a second to decrypt in PHP. That happens once per worker, when the
connection is first used.

## Usage

### A batch exchange

```php
use Kipchak\Driver\Sftp\Sftp;

$sftp = Sftp::get();

foreach ($sftp->files('inbound', 'orders-*.csv') as $file) {
    $orders = $sftp->read($file->path);
    // ... process the orders ...
    $sftp->move($file->path, 'archive/' . $file->name);
}

$sftp->write('outbound/confirmations-' . date('Ymd') . '.csv', $csv);
```

`files()` returns the regular files in a directory, and `list()` returns files and directories, both sorted by name
and filtered by an optional shell wildcard. Each is a `FileInfo` with `path`, `name`, `type`, `size`, `modified`
(a `DateTimeImmutable`), `permissions`, `isFile()` and `isDirectory()`. `path` can be passed straight back to the
other methods.

### Reading

```php
$sftp->read('inbound/orders.csv');                         // the contents, as a string
$sftp->download('inbound/large.zip', '/tmp/large.zip');    // streamed to a local file
$stream = $sftp->readStream('inbound/large.zip');          // a stream on local disk, positioned at the start
$sftp->stat('inbound/orders.csv');                         // a FileInfo, or null when there is no such file
$sftp->exists('inbound/orders.csv');
```

`download()` writes to `<file>.part` and renames it when the download is complete.

### Writing

```php
$sftp->write('outbound/report.csv', $csv);                 // from a string
$sftp->upload('/tmp/export.zip', 'outbound/export.zip');   // streamed from a local file
$sftp->writeStream('outbound/export.zip', $stream);        // from a seekable stream's current position
$sftp->createDirectory('archive/2026/10');                 // with any missing parents; no error if it exists
$sftp->move('outbound/report.csv', 'sent/report.csv');
$sftp->move('a.csv', 'b.csv', overwrite: true);
$sftp->delete('sent/report.csv');
$sftp->deleteDirectory('archive/2025');                    // and everything in it
```

Writing replaces a file that is already there. The file is written as `.<name>.<random>.part` in the same
directory, its size is checked against what was sent, and it is renamed into place. On OpenSSH and other servers
with the `posix-rename@openssh.com` extension, the replacement is atomic. On other servers the old file is deleted
first, so there is a moment when no file is there. A failed write deletes its temporary file.

`move()` refuses to replace an existing file unless `overwrite` is true.

### Errors

```php
use Kipchak\Driver\Sftp\Exception\FileNotFoundException;
use Kipchak\Driver\Sftp\Exception\SftpConnectionException;
use Kipchak\Driver\Sftp\Exception\SftpException;

try {
    $contents = Sftp::get()->read('inbound/orders.csv');
} catch (FileNotFoundException $exception) {
    // No such file or directory
} catch (SftpConnectionException $exception) {
    // The server could not be reached, its host key did not match, or it rejected the credentials
} catch (SftpException $exception) {
    // Anything else the server refused: $exception->sftpError, e.g. "NET_SFTP_STATUS_PERMISSION_DENIED: Permission denied"
}
```

| Exception | Meaning |
|---|---|
| `SftpException` | The base class. `$path` is the remote path, `$sftpError` the server's status. |
| `FileNotFoundException` | No such file or directory |
| `SftpConnectionException` | The server could not be reached, or the connection was lost and could not be re-opened |
| `HostKeyVerificationException` | The server's host key is not configured. `$fingerprint` is the key it presented. |
| `AuthenticationException` | The server rejected the username, password or key |

A path that leads outside `root`, and a configuration mistake, throw `InvalidArgumentException`.

### Connections in worker mode

Under FrankenPHP's worker mode, each worker opens one connection per configured server on first use and keeps it,
so later requests do not repeat the SSH handshake. SFTP servers close idle connections, and restart. When an
operation finds that a kept connection has gone, the driver opens a new one and repeats the operation once. Every
operation is safe to repeat: a repeated write starts again from the beginning, into a new temporary file.

A server that cannot be reached at all fails the operation straight away with a `SftpConnectionException`, and the
next operation tries again. Call `Sftp::get()->disconnect()` to close a connection yourself.

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite Unit

# Integration tests run against a real OpenSSH server, built from tests/Integration/Dockerfile:
tests/Integration/start-sftp.sh
vendor/bin/phpunit
docker rm -f kipchak-sftp
```

## What is a Kipchak Driver?

Kipchak Drivers are used to connect Kipchak to various data sources or storage systems.

They provide a standardized interface for interacting with different data sources, allowing developers to
focus on building their applications rather than dealing with the complexities of each data source.

Drivers are basically Container Dependencies injected into Kipchak's Service Container.

## Licence

MIT.
