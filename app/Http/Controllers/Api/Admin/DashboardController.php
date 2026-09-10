<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\DashboardService;

/**
 * Read-only, mirroring the Blade dashboard exactly via the SAME
 * DashboardService — no duplicated calculation logic between the two
 * surfaces. Available to any authenticated admin (matching the existing
 * dashboard's own precedent of showing every stat to any admin who can
 * reach the page, with only individual QUICK-ACTION links gated by
 * specific permissions — not the underlying numbers themselves).
 */
class DashboardController extends BaseApiController
{
    public function __construct(protected DashboardService $service) {}

    public function index()
    {
        return $this->success([
            'stats' => $this->service->getStats(),
        ]);
    }
}
