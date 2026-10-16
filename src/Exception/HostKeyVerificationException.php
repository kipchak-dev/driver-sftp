<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Exception;

/**
 * The server's host key is not one of the configured fingerprints. Either the server's key has changed, or the
 * connection is not reaching the real server. No credentials are sent.
 */
final class HostKeyVerificationException extends SftpConnectionException
{
    public function __construct(string $message, public readonly ?string $fingerprint = null)
    {
        parent::__construct($message);
    }
}
