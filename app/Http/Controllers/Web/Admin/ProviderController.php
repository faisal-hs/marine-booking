<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Provider\RejectProviderRequest;
use App\Models\Provider;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Services\ProviderVerificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProviderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ProviderVerificationService $service,
        protected ProviderRepositoryInterface $repository,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Provider::class);

        $providers = $this->repository->paginateForAdmin($request->only(['status', 'search']));

        return view('admin.providers.index', [
            'providers' => $providers,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    public function show(Provider $provider): View
    {
        $this->authorize('viewAny', Provider::class);

        $provider->load('user');

        return view('admin.providers.show', ['provider' => $provider]);
    }

    public function verify(Provider $provider): RedirectResponse
    {
        $this->authorize('verify', Provider::class);

        try {
            $this->service->verify($provider);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.providers.verified'));
    }

    public function reject(RejectProviderRequest $request, Provider $provider): RedirectResponse
    {
        $this->authorize('reject', Provider::class);

        try {
            $this->service->reject($provider, $request->input('rejection_reason'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.providers.rejected'));
    }
}
