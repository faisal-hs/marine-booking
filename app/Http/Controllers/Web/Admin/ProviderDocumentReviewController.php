<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\DocumentType\ApproveDocumentRequest;
use App\Http\Requests\Web\Admin\DocumentType\RejectDocumentRequest;
use App\Http\Requests\Web\Admin\DocumentType\RequestDocumentUpdateRequest;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Services\ProviderDocumentReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Reviewing what providers uploaded: the queue, and approving, rejecting or asking for a new version. Every decision is in the history and the provider is told. */
class ProviderDocumentReviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ProviderDocumentReviewService $review) {}

    public function index(Request $request): View
    {
        $this->authorize('review', ProviderDocument::class);

        // What waits for a decision comes first: that is what an admin opens this screen for.
        $status = $request->input('status', 'pending');
        $counts = ProviderDocument::query()->current()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();
        $counts['all'] = array_sum($counts);

        $documents = ProviderDocument::query()->current()->with('provider')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->oldest()->oldest('id')->paginate(20)->withQueryString();

        return view('admin.verification-documents.index', ['documents' => $documents, 'status' => $status, 'counts' => $counts]);
    }

    public function approve(ApproveDocumentRequest $request, Provider $provider, string $document): RedirectResponse
    {
        $this->authorize('review', ProviderDocument::class);
        $document = $this->version($provider, $document);

        return $this->act(fn () => $this->review->approve($document, $request->user(), $request->validated('expires_at') ? Carbon::parse($request->validated('expires_at')) : null), 'approved', $provider, $document);
    }

    public function reject(RejectDocumentRequest $request, Provider $provider, string $document): RedirectResponse
    {
        $this->authorize('review', ProviderDocument::class);
        $document = $this->version($provider, $document);

        return $this->act(fn () => $this->review->reject($document, $request->user(), $request->validated('note')), 'rejected', $provider, $document);
    }

    public function requestUpdate(RequestDocumentUpdateRequest $request, Provider $provider): RedirectResponse
    {
        $this->authorize('review', ProviderDocument::class);

        try {
            $this->review->requestUpdate($provider, $request->validated('document_type'), $request->user(), $request->validated('reason'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.verification_documents.done.update_requested'));
    }

    /** A document of THIS provider, among every version (a replaced one is found too, and then refused with the reason, not hidden as "not found"). */
    private function version(Provider $provider, string $uuid): ProviderDocument
    {
        return $provider->documentVersions()->where('uuid', $uuid)->firstOrFail();
    }

    /** Runs a decision the caller was already authorized for (approve() and reject() check permission before they look the document up). */
    private function act(callable $action, string $done, Provider $provider, ProviderDocument $document): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __("admin.verification_documents.done.{$done}"));
    }
}
