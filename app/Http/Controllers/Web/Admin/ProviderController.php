<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Provider\RejectProviderRequest;
use App\Http\Requests\Web\Admin\Provider\StoreProviderRequest;
use App\Http\Requests\Web\Admin\Provider\UpdateProviderRequest;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Services\ProviderDocumentService;
use App\Services\ProviderManagementService;
use App\Services\ProviderVerificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ProviderVerificationService $service,
        protected ProviderRepositoryInterface $repository,
        protected ProviderDocumentService $documents,
        protected ProviderManagementService $management,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Provider::class);

        $filters = $request->only(['status', 'account', 'search']);

        return view('admin.providers.index', [
            'providers' => $this->repository->paginateForAdmin($filters),
            'filters' => $filters,
        ]);
    }

    public function show(Provider $provider): View
    {
        $this->authorize('viewAny', Provider::class);

        $provider->load(['user', 'documents']);

        return view('admin.providers.show', [
            'provider' => $provider,
            'summary' => $this->management->summary($provider),
            'activities' => $provider->activities()->with('category')->latest()->limit(10)->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Provider::class);

        return view('admin.providers.create');
    }

    public function store(StoreProviderRequest $request): RedirectResponse
    {
        $provider = $this->management->create($request->payload(), $request->verifyNow());

        return redirect()->route('admin.providers.show', $provider)->with('status', __('admin.providers.created'));
    }

    public function edit(Provider $provider): View
    {
        $this->authorize('update', $provider);

        $provider->load('user');

        return view('admin.providers.edit', ['provider' => $provider]);
    }

    public function update(UpdateProviderRequest $request, Provider $provider): RedirectResponse
    {
        $this->management->update($provider, $request->payload());

        return redirect()->route('admin.providers.show', $provider)->with('status', __('admin.providers.updated'));
    }

    public function suspend(Provider $provider): RedirectResponse
    {
        $this->authorize('update', $provider);

        $this->management->suspend($provider);

        return back()->with('status', __('admin.providers.suspended'));
    }

    public function reactivate(Provider $provider): RedirectResponse
    {
        $this->authorize('update', $provider);

        $this->management->reactivate($provider);

        return back()->with('status', __('admin.providers.reactivated'));
    }

    public function destroy(Provider $provider): RedirectResponse
    {
        $this->authorize('delete', $provider);

        try {
            $this->management->delete($provider);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.providers.index')->with('status', __('admin.providers.deleted'));
    }

    public function restore(Provider $provider): RedirectResponse
    {
        $this->authorize('restore', $provider);

        $this->management->restore($provider);

        return redirect()->route('admin.providers.show', $provider)->with('status', __('admin.providers.restored'));
    }

    public function verify(Provider $provider): RedirectResponse
    {
        $this->authorize('verify', Provider::class);

        try {
            $this->service->verify($provider, request()->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.providers.verified'));
    }

    public function reject(RejectProviderRequest $request, Provider $provider): RedirectResponse
    {
        $this->authorize('reject', Provider::class);

        try {
            $this->service->reject($provider, $request->input('rejection_reason'), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.providers.rejected'));
    }

    /**
     * The only way a verification document can be read. The {document:uuid} binding is
     * scoped to the provider in the URL, so a document id paired with the wrong
     * provider is a 404, and the policy limits it to admins who may view providers.
     */
    public function downloadDocument(Provider $provider, ProviderDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return $this->documents->download($document);
    }
}
