<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\ProviderRequest\RejectProviderRequestRequest;
use App\Models\ProviderDocument;
use App\Models\ProviderRequest;
use App\Services\ProviderDocumentService;
use App\Services\ProviderRequestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Requests to become a provider: a list, the details with the documents, and the decision. */
class ProviderRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ProviderRequestService $service,
        protected ProviderDocumentService $documents,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProviderRequest::class);

        // Waiting requests first: that is what an admin opens this screen for.
        $filters = array_merge(['status' => 'pending'], $request->only(['status', 'source', 'search']));

        return view('admin.provider-requests.index', [
            'requests' => $this->service->paginateForAdmin($filters),
            'filters' => $filters,
            'counts' => $this->service->counts(),
        ]);
    }

    public function show(ProviderRequest $providerRequest): View
    {
        $this->authorize('view', $providerRequest);

        $providerRequest->load(['user', 'provider.documents', 'reviewer']);

        return view('admin.provider-requests.show', ['providerRequest' => $providerRequest]);
    }

    public function approve(Request $request, ProviderRequest $providerRequest): RedirectResponse
    {
        $this->authorize('review', $providerRequest);

        try {
            $this->service->approve($providerRequest, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.provider-requests.show', $providerRequest)->with('status', __('admin.provider_requests.approved'));
    }

    public function reject(RejectProviderRequestRequest $request, ProviderRequest $providerRequest): RedirectResponse
    {
        $this->authorize('review', $providerRequest);

        try {
            $this->service->reject($providerRequest, $request->user(), $request->validated('rejection_reason'));
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.provider-requests.show', $providerRequest)->with('status', __('admin.provider_requests.rejected'));
    }

    public function downloadDocument(ProviderRequest $providerRequest, ProviderDocument $document): StreamedResponse
    {
        $this->authorize('view', $providerRequest);

        // Only a document of the provider this request is about.
        abort_unless($document->provider_id === $providerRequest->provider_id, 404);

        return $this->documents->download($document);
    }
}
