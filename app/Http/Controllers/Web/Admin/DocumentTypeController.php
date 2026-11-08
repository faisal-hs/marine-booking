<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\DocumentType\SaveDocumentTypeRequest;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\VerificationDocumentType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The kinds of document providers are asked for: an admin adds them, marks each mandatory or optional, switches them off, and orders them.
 * Applying as a provider needs a file for every ACTIVE MANDATORY type. A type that documents already use is switched off, never deleted.
 */
class DocumentTypeController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('manage', VerificationDocumentType::class);

        $types = VerificationDocumentType::query()->orderBy('sort_order')->orderBy('id')->get();
        $inUse = ProviderDocument::query()->current()->selectRaw('type, COUNT(*) AS total')->groupBy('type')->pluck('total', 'type');

        return view('admin.document-types.index', ['types' => $types, 'inUse' => $inUse]);
    }

    public function create(): View
    {
        $this->authorize('manage', VerificationDocumentType::class);

        return view('admin.document-types.form', ['type' => null]);
    }

    public function store(SaveDocumentTypeRequest $request): RedirectResponse
    {
        $this->authorize('manage', VerificationDocumentType::class);

        VerificationDocumentType::create($this->values($request) + ['key' => $request->validated('key')]);

        return redirect()->route('admin.document-types.index')->with('status', __('admin.document_types.created'));
    }

    public function edit(VerificationDocumentType $documentType): View
    {
        $this->authorize('manage', VerificationDocumentType::class);

        return view('admin.document-types.form', ['type' => $documentType]);
    }

    public function update(SaveDocumentTypeRequest $request, VerificationDocumentType $documentType): RedirectResponse
    {
        $this->authorize('manage', VerificationDocumentType::class);

        $documentType->update($this->values($request));

        return redirect()->route('admin.document-types.index')->with('status', __('admin.document_types.updated'));
    }

    public function destroy(VerificationDocumentType $documentType): RedirectResponse
    {
        $this->authorize('manage', VerificationDocumentType::class);

        if (ProviderDocument::where('type', $documentType->key)->exists() || ProviderDocumentEvent::where('document_type', $documentType->key)->exists()) {
            return back()->with('error', __('admin.document_types.in_use'));
        }

        $documentType->delete();

        return redirect()->route('admin.document-types.index')->with('status', __('admin.document_types.deleted'));
    }

    /** @return array<string, mixed> */
    private function values(SaveDocumentTypeRequest $request): array
    {
        return [
            'title_en' => $request->validated('title_en'), 'title_ar' => $request->validated('title_ar'),
            'description_en' => $request->validated('description_en'), 'description_ar' => $request->validated('description_ar'),
            'is_mandatory' => $request->boolean('is_mandatory'), 'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) ($request->validated('sort_order') ?? 0),
        ];
    }
}
