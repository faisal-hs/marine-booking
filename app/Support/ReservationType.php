<?php

namespace App\Support;

use App\Enums\BookingModel;

/**
 * What a customer has to say to book an option, in four words: the RESERVATION TYPE of a booking option. It is the booking model, grouped by what the booking is MADE of:
 *
 *   persons   a number of people, by kind (adult, child, infant)        booking model per_person       send `guests`, or `participants` {adult, child, infant}
 *   units     a number of items (a jet ski, a kayak)                    booking model per_unit         send `quantity`
 *   time      a length of time, in hours or in days                     booking model per_hour/per_day send `duration`
 *   fixed     one price for the whole booking, however many people      per_trip, per_group, fixed, package: nothing more to send (`guests` may be sent for the places it takes)
 *
 * `booking_model` stays what it was (the exact model); the reservation type is the answer an app needs to draw the right form.
 */
final class ReservationType
{
    public const PERSONS = 'persons';

    public const UNITS = 'units';

    public const TIME = 'time';

    public const FIXED = 'fixed';

    public const ALL = [self::PERSONS, self::UNITS, self::TIME, self::FIXED];

    public static function of(BookingModel $model): string
    {
        return match ($model) {
            BookingModel::PerPerson => self::PERSONS,
            BookingModel::PerUnit => self::UNITS,
            BookingModel::PerHour, BookingModel::PerDay => self::TIME,
            BookingModel::PerTrip, BookingModel::PerGroup, BookingModel::Fixed, BookingModel::Package => self::FIXED,
        };
    }

    /** What the price is for, as one word an app can print after it: "per person", "per unit", "per hour", "per day", "per trip", "per group", "fixed", "package". */
    public static function priceUnit(BookingModel $model): string
    {
        return $model->value;
    }
}
