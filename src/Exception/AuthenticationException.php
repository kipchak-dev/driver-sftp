<?php

declare(strict_types=1);

namespace Kipchak\Driver\Sftp\Exception;

/**
 * The server rejected the configured username, password or private key.
 */
final class AuthenticationException extends SftpConnectionException
{
}
