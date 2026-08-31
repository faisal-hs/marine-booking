<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'total_users' => User::count(),
            'total_customers' => User::where('user_type', 'customer')->count(),
            'total_providers' => User::where('user_type', 'provider')->count(),
            'pending_verifications' => Provider::where('verification_status', 'pending')->count(),
        ];

        $recentUsers = User::latest()->limit(5)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers'));
    }
}
