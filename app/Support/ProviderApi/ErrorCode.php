<?php

namespace App\Support\ProviderApi;

/** The error codes of the provider app API (spec section 7), plus the generic ones for a status with no specific code. */
final class ErrorCode
{
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const PROVIDER_NOT_APPROVED = 'PROVIDER_NOT_APPROVED';

    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    public const INVALID_STATUS_TRANSITION = 'INVALID_STATUS_TRANSITION';

    public const BOOKING_CAPACITY_CONFLICT = 'BOOKING_CAPACITY_CONFLICT';

    public const AVAILABILITY_BOOKING_CONFLICT = 'AVAILABILITY_BOOKING_CONFLICT';

    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const DECLINE_REASON_REQUIRED = 'DECLINE_REASON_REQUIRED';

    // Not in the spec: a provider cancels a confirmed booking instead of declining a pending one (see docs/PROVIDER_API.md, D1).
    public const CANCELLATION_REASON_REQUIRED = 'CANCELLATION_REASON_REQUIRED';

    public const REFUND_FAILED = 'REFUND_FAILED';

    public const DUPLICATE_WITHDRAWAL = 'DUPLICATE_WITHDRAWAL';

    public const REPLY_ALREADY_EXISTS = 'REPLY_ALREADY_EXISTS';

    public const ACTIVITY_SUSPENDED_BY_ADMIN = 'ACTIVITY_SUSPENDED_BY_ADMIN';

    public const BANK_ACCOUNT_REQUIRED = 'BANK_ACCOUNT_REQUIRED';

    public const INSUFFICIENT_AVAILABLE_BALANCE = 'INSUFFICIENT_AVAILABLE_BALANCE';

    // Not in the spec: the status has no more specific code.
    public const FORBIDDEN = 'FORBIDDEN';

    public const CONFLICT = 'CONFLICT';

    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const REQUEST_FAILED = 'REQUEST_FAILED';

    // The spec has RESOURCE_NOT_OWNED (403) for something that exists but belongs to another provider. It is deliberately NOT used:
    // saying "not yours" instead of "not found" tells a provider which ids exist. Another provider's resource is simply RESOURCE_NOT_FOUND.
}
