<?php

namespace App\Exceptions;

use App\Support\ProviderApi\ErrorCode;
use RuntimeException;

/**
 * A failure of the provider app API with the spec's error code (for example INVALID_STATUS_TRANSITION, 409). The handler in
 * bootstrap/app.php renders it as { "status": false, "code": ..., "message": ..., "errors": ... }.
 */
class ProviderApiException extends RuntimeException
{
    /** @param  array<string, mixed>|null  $errors */
    public function __construct(string $message, public readonly int $status, public readonly string $errorCode, public readonly ?array $errors = null)
    {
        parent::__construct($message);
    }

    public static function conflict(string $message, string $code): self
    {
        return new self($message, 409, $code);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self($message, 404, ErrorCode::RESOURCE_NOT_FOUND);
    }
}
