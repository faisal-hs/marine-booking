<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\Document\StoreProviderDocumentRequest;
use App\Http\Resources\Provider\ProviderDocumentResource;
use App\Models\ProviderDocument;
use App\Repositories\Contracts\ProviderDocumentRepositoryInterface;
use App\Services\ProviderDocumentService;
use Illuminate\Http\Request;

class DocumentController extends BaseApiController
{
    public function __construct(
        protected ProviderDocumentService $service,
        protected ProviderDocumentRepositoryInterface $repository,
    ) {}

    public function index(Request $request)
    {
        return $this->success(ProviderDocumentResource::collection(
            $this->repository->forProvider($request->user()->provider->id)
        ));
    }

    public function store(StoreProviderDocumentRequest $request)
    {
        $document = $this->service->upload(
            $request->user()->provider,
            $request->file('file'),
            $request->input('type'),
            $request->input('title'),
        );

        return $this->created(new ProviderDocumentResource($document), 'Document uploaded successfully.');
    }

    public function destroy(Request $request, ProviderDocument $document)
    {
        // Someone else's document is answered as "not found", not "forbidden", so
        // document ids cannot be probed.
        abort_if($document->provider_id !== $request->user()->provider->id, 404);

        $this->service->delete($document);

        return $this->noContent('Document deleted successfully.');
    }
}
