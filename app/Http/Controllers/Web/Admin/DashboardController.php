<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Each module maps to its 'view' permission — the Blade view checks
     * this same permission (plus create/update/delete individually) via
     * @can, so this array only needs to define what modules exist and
     * which permission key gates visibility of the module itself.
     */
    public function index(): View
    {
        $modules = [
            ['label' => 'Users', 'permission' => 'users', 'icon' => '👤'],
            ['label' => 'Providers', 'permission' => 'providers', 'icon' => '🏢'],
            ['label' => 'Customers', 'permission' => 'customers', 'icon' => '🧑'],
            ['label' => 'Categories', 'permission' => 'categories', 'icon' => '🏷️'],
            ['label' => 'Activities', 'permission' => 'activities', 'icon' => '🗺️'],
            ['label' => 'Reservations', 'permission' => 'reservations', 'icon' => '📅'],
            ['label' => 'Payments', 'permission' => 'payments', 'icon' => '💳'],
            ['label' => 'Notifications', 'permission' => 'notifications', 'icon' => '🔔'],
            ['label' => 'Reports', 'permission' => 'reports', 'icon' => '📊'],
            ['label' => 'Roles & Permissions', 'permission' => 'roles', 'icon' => '🔒'],
            ['label' => 'Settings', 'permission' => 'settings', 'icon' => '⚙️'],
        ];

        return view('admin.dashboard', ['modules' => $modules]);
    }
}
