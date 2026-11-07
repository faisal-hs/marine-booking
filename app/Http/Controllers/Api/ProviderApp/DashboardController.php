<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Services\ProviderApp\ProviderDashboard;
use Illuminate\Http\Request;

class DashboardController extends ProviderAppController
{
    public function __construct(private readonly ProviderDashboard $dashboard) {}

    public function show(Request $request)
    {
        return $this->ok($this->dashboard->summary($request->user(), $this->provider($request)));
    }
}
