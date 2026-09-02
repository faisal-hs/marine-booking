<?php

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PaymentRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Looks up a payment by the gateway's own provider_reference — the
     * primary lookup path when a webhook arrives, since the webhook
     * payload carries the gateway's reference, not our internal id/uuid.
     */
    public function findByProviderReference(string $reference): ?Payment;

    public function forCustomer(int $customerId, array $filters, int $perPage = 15): LengthAwarePaginator;

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;
}
