<?php

namespace App\Services;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityFeatureValueRepositoryInterface;
use App\Repositories\Contracts\FeatureDefinitionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActivityFeatureService
{
    public function __construct(
        protected FeatureDefinitionRepositoryInterface $definitionRepository,
        protected ActivityFeatureValueRepositoryInterface $valueRepository,
    ) {}

    /**
     * Builds validation rules dynamically from a category's active feature
     * definitions — one query, not one per field. Accepts a raw category
     * id (not an Activity) so it can be used both for an existing
     * activity's feature sync AND for the combined create-activity
     * endpoint, where no Activity row exists yet at validation time.
     */
    public function buildValidationRulesForCategory(int $categoryId): array
    {
        $definitions = $this->definitionRepository->forCategory($categoryId);

        $rules = [];

        foreach ($definitions as $definition) {
            $base = $definition->is_required ? 'required' : 'nullable';
            $field = "features.{$definition->key}";

            $rules[$field] = match ($definition->type) {
                'text' => [$base, 'string', 'max:1000'],
                'number' => [$base, 'numeric'],
                'boolean' => [$base, 'boolean'],
                'select' => [$base, Rule::in(collect($definition->options)->pluck('key'))],
                'multi_select' => [$base, 'array'],
            };

            if ($definition->type === 'multi_select') {
                $rules["{$field}.*"] = [Rule::in(collect($definition->options)->pluck('key'))];
            }
        }

        return $rules;
    }

    /**
     * @deprecated kept for the standalone SyncActivityFeaturesRequest;
     * new call sites should use buildValidationRulesForCategory() directly.
     */
    public function buildValidationRules(Activity $activity): array
    {
        return $this->buildValidationRulesForCategory($activity->category_id);
    }

    /**
     * Persists submitted feature values. Re-validates that every submitted
     * key actually belongs to the activity's category — defense in depth,
     * since a key with no corresponding active definition would otherwise
     * pass through the FormRequest's dynamically-built rules completely
     * unvalidated (Laravel only validates keys it has rules for).
     */
    public function sync(Activity $activity, array $values): void
    {
        $definitions = $this->definitionRepository->forCategory($activity->category_id)->keyBy('key');

        DB::transaction(function () use ($activity, $values, $definitions) {
            foreach ($values as $key => $value) {
                $definition = $definitions->get($key);

                if (! $definition) {
                    throw ValidationException::withMessages([
                        "features.{$key}" => ["'{$key}' is not a valid feature for this activity's category."],
                    ]);
                }

                $columns = match ($definition->type) {
                    'text', 'select' => ['value_text' => $value, 'value_number' => null, 'value_boolean' => null, 'value_json' => null],
                    'number' => ['value_text' => null, 'value_number' => $value, 'value_boolean' => null, 'value_json' => null],
                    'boolean' => ['value_text' => null, 'value_number' => null, 'value_boolean' => (bool) $value, 'value_json' => null],
                    'multi_select' => ['value_text' => null, 'value_number' => null, 'value_boolean' => null, 'value_json' => $value],
                };

                $this->valueRepository->upsert($activity->id, $definition->id, $columns);
            }
        });
    }
}
