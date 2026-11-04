<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Customer\StoreCustomerRequest;
use App\Http\Requests\Web\Admin\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\CustomerManagementService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected CustomerManagementService $service,
        protected CustomerRepositoryInterface $repository,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->only(['account', 'search']);

        return view('admin.customers.index', [
            'customers' => $this->repository->paginateForAdmin($filters),
            'filters' => $filters,
        ]);
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        $customer->load('user');

        return view('admin.customers.show', [
            'customer' => $customer,
            'summary' => $this->service->summary($customer),
            'reservations' => $customer->reservations()->with('activity')->latest()->limit(10)->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('admin.customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = $this->service->create($request->payload());

        return redirect()->route('admin.customers.show', $customer)->with('status', __('admin.customers.created'));
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        $customer->load('user');

        return view('admin.customers.edit', ['customer' => $customer]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->service->update($customer, $request->payload());

        return redirect()->route('admin.customers.show', $customer)->with('status', __('admin.customers.updated'));
    }

    public function suspend(Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->service->suspend($customer);

        return back()->with('status', __('admin.customers.suspended'));
    }

    public function reactivate(Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->service->reactivate($customer);

        return back()->with('status', __('admin.customers.reactivated'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        try {
            $this->service->delete($customer);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.customers.index')->with('status', __('admin.customers.deleted'));
    }

    public function restore(Customer $customer): RedirectResponse
    {
        $this->authorize('restore', $customer);

        $this->service->restore($customer);

        return redirect()->route('admin.customers.show', $customer)->with('status', __('admin.customers.restored'));
    }
}
