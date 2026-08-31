<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Shows every module link unconditionally for now.
     *
     * TODO(Module 02): gate each entry by the authenticated admin's
     * actual permissions once the RBAC system exists — right now this
     * is a static shell so admin web auth can ship without pretending
     * we have real permission data.
     */
    public function index(): View
    {
        $modules = [
            ['label' => 'Users', 'icon' => 'users'],
            ['label' => 'Providers', 'icon' => 'building'],
            ['label' => 'Customers', 'icon' => 'user'],
            ['label' => 'Categories', 'icon' => 'tag'],
            ['label' => 'Activities', 'icon' => 'map'],
            ['label' => 'Reservations', 'icon' => 'calendar'],
            ['label' => 'Payments', 'icon' => 'credit-card'],
            ['label' => 'Notifications', 'icon' => 'bell'],
            ['label' => 'Reports', 'icon' => 'chart'],
            ['label' => 'Roles & Permissions', 'icon' => 'lock'],
            ['label' => 'Settings', 'icon' => 'cog'],
        ];

        return view('admin.dashboard', ['modules' => $modules]);
    }
}
