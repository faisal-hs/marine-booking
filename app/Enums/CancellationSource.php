<?php

namespace App\Enums;

/** Who initiated a cancellation. Recorded on the booking, with the account that did it. */
enum CancellationSource: string
{
    case Customer = 'customer';
    case Provider = 'provider';
    case Admin = 'admin';
    case System = 'system';
}
