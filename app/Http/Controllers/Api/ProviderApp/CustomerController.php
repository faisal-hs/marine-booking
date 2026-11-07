<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\ListCustomersRequest;
use App\Services\ProviderApp\ProviderCustomers;

class CustomerController extends ProviderAppController
{
    public function __construct(private readonly ProviderCustomers $customers) {}

    public function index(ListCustomersRequest $request)
    {
        $result = $this->customers->list($this->provider($request), $request->only(['tier', 'q']), $request->perPage(), $request->page());

        return $this->page($result['page'], ['summary' => $result['summary'], 'customers' => $result['customers']]);
    }
}
