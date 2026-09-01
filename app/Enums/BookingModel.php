<?php

namespace App\Enums;


enum BookingModel: string
{
    case PerPerson = 'per_person';
    case PerGroup = 'per_group';
    case PerTrip = 'per_trip';
    case PerUnit = 'per_unit';
    case PerHour = 'per_hour';
    case PerDay = 'per_day';
    case Fixed = 'fixed';
    case Package = 'package';

    /** Only per_person prices vary by adult/child/infant. */
    public function usesParticipantPricing(): bool
    {
        return $this === self::PerPerson;
    }

    /** per_hour/per_day multiply price × a duration value. */
    public function usesDuration(): bool
    {
        return in_array($this, [self::PerHour, self::PerDay], true);
    }

    /** per_unit multiplies price × a quantity value (e.g. 2 jet skis). */
    public function usesQuantity(): bool
    {
        return $this === self::PerUnit;
    }

    /**
     * per_trip, per_group, fixed, and package all charge one configured
     * amount regardless of headcount — capacity may still be validated,
     * but the price itself is never multiplied by participants.
     */
    public function isFlatRegardlessOfParticipants(): bool
    {
        return in_array($this, [self::PerTrip, self::PerGroup, self::Fixed, self::Package], true);
    }
}
