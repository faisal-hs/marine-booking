<?php

namespace App\Http\Requests\Web\Admin\Activity;

use App\Repositories\Contracts\FeatureDefinitionRepositoryInterface;
use App\Services\ActivityFeatureService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The category-specific attributes (dive depth, certification required ...), validated by the
 * same per-category rules the provider API uses.
 */
class SyncFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.update');
    }

    protected function prepareForValidation(): void
    {
        $definitions = app(FeatureDefinitionRepositoryInterface::class)->forCategory($this->route('activity')->category_id);
        $features = (array) $this->input('features', []);

        // A multi-select with nothing ticked sends nothing at all; without this the old
        // choices could never be cleared. Stray empty entries are dropped too.
        foreach ($definitions as $definition) {
            if ($definition->type === 'multi_select') {
                $features[$definition->key] = array_values(array_filter(
                    (array) ($features[$definition->key] ?? []),
                    fn ($choice) => $choice !== null && $choice !== ''
                ));
            }
        }

        $this->merge(['features' => $features]);
    }

    public function rules(): array
    {
        return ['features' => ['present', 'array']]
            + app(ActivityFeatureService::class)->buildValidationRulesForCategory($this->route('activity')->category_id);
    }

    /**
     * The submitted values (already checked by the per-category rules above). Deliberately NOT
     * validated(): that would silently drop a key the category does not define, such as a stale
     * form posted from another tab after the category changed. ActivityFeatureService::sync
     * refuses such a key with a clear message instead, exactly as the provider API does.
     */
    public function payload(): array
    {
        return (array) $this->input('features', []);
    }
}
