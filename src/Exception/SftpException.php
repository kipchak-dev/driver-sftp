<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Exception;

use RuntimeException;

/**
 * An SFTP operation failed: permission denied, a destination that already exists, a transfer that did not arrive
 * complete, ...
 *
 * $path is the remote path the operation was working on, and $sftpError the server's status, named as in the SFTP
 * protocol: for example "SSH_FX_PERMISSION_DENIED: Permission denied".
 */
class SftpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        public readonly ?string $sftpError = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
