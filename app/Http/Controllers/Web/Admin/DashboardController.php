<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $service) {}

    public function index(): View
    {
        return view('admin.dashboard', [
            'stats' => $this->service->getStats(),
            'recentUsers' => $this->service->recentUsers(),
            'recentReservations' => $this->service->recentReservations(),
            'recentPayments' => $this->service->recentPayments(),
        ]);
    }
}
