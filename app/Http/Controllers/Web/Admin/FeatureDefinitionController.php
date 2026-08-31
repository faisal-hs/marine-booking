<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\FeatureDefinition\StoreFeatureDefinitionRequest;
use App\Http\Requests\Web\Admin\FeatureDefinition\UpdateFeatureDefinitionRequest;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Services\FeatureDefinitionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FeatureDefinitionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected FeatureDefinitionService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', FeatureDefinition::class);

        $definitions = FeatureDefinition::with('category')
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->paginate(15);

        return view('admin.feature-definitions.index', compact('definitions'));
    }

    public function create(): View
    {
        $this->authorize('create', FeatureDefinition::class);

        $categories = Category::orderBy('name')->get();

        return view('admin.feature-definitions.create', compact('categories'));
    }

    public function store(StoreFeatureDefinitionRequest $request): RedirectResponse
    {
        try {
            $this->service->create($request->payload());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('admin.feature-definitions.index')->with('status', __('admin.feature_definitions.created'));
    }

    public function edit(FeatureDefinition $featureDefinition): View
    {
        $this->authorize('update', $featureDefinition);

        $categories = Category::orderBy('name')->get();

        return view('admin.feature-definitions.edit', compact('featureDefinition', 'categories'));
    }

    public function update(UpdateFeatureDefinitionRequest $request, FeatureDefinition $featureDefinition): RedirectResponse
    {
        try {
            $this->service->update($featureDefinition, $request->payload());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('admin.feature-definitions.index')->with('status', __('admin.feature_definitions.updated'));
    }

    public function destroy(FeatureDefinition $featureDefinition): RedirectResponse
    {
        $this->authorize('delete', $featureDefinition);

        $this->service->delete($featureDefinition);

        return redirect()->route('admin.feature-definitions.index')->with('status', __('admin.feature_definitions.deleted'));
    }
}
