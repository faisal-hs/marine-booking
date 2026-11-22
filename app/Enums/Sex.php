<?php

namespace App\Enums;

/** The sex of the person behind an account (users.sex): male or female. It is optional: an account that did not say has none. */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
