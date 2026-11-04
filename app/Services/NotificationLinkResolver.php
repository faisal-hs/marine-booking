<?php

namespace App\Services;

use App\Models\User;

/**
 * Turns the identifiers inside an admin notification (the reservation, payment, provider or
 * activity it is about) into a link to that page, so the inbox can open the thing a
 * notification refers to. A link is offered only when the viewer is allowed to see the page.
 */
class NotificationLinkResolver
{
    /** [data key, route, permission needed to open it] */
    private const TARGETS = [
        ['provider_request_uuid', 'admin.provider-requests.show', 'provider_requests.view'],
        ['reservation_uuid', 'admin.reservations.show', 'reservations.view'],
        ['payment_uuid', 'admin.payments.show', 'payments.view'],
        ['provider_uuid', 'admin.providers.show', 'providers.view'],
        ['activity_uuid', 'admin.activities.show', 'activities.view'],
    ];

    public function urlFor(array $data, User $viewer): ?string
    {
        foreach (self::TARGETS as [$key, $route, $permission]) {
            if (! empty($data[$key]) && is_string($data[$key]) && $viewer->can($permission)) {
                return route($route, $data[$key]);
            }
        }

        return null;
    }
}
