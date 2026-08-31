<?php

namespace App\Services;

use App\Models\FeatureDefinition;
use App\Repositories\Contracts\FeatureDefinitionRepositoryInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeatureDefinitionService
{
    public function __construct(protected FeatureDefinitionRepositoryInterface $repository) {}

    public function create(array $data): FeatureDefinition
    {
        $this->assertKeyIsUnique($data['key'], $data['category_id']);

        if (! empty($data['options_raw'])) {
            $data['options'] = $this->parseOptions($data['options_raw']);
        }
        unset($data['options_raw']);

        return $this->repository->create($data);
    }

    public function update(FeatureDefinition $definition, array $data): FeatureDefinition
    {
        $targetCategoryId = $data['category_id'] ?? $definition->category_id;

        if ($data['key'] !== $definition->key || $targetCategoryId !== $definition->category_id) {
            $this->assertKeyIsUnique($data['key'], $targetCategoryId, $definition->id);
        }

        if (array_key_exists('options_raw', $data)) {
            $data['options'] = $data['options_raw'] ? $this->parseOptions($data['options_raw']) : null;
            unset($data['options_raw']);
        }

        return $this->repository->update($definition, $data);
    }

    public function delete(FeatureDefinition $definition): void
    {
        // Soft delete only — existing activity_feature_values referencing this
        // definition remain intact and readable; only new activities lose the
        // ability to set this feature (forCategory() filters status='active',
        // and soft-deleted rows never match a status filter meaningfully anyway).
        $this->repository->delete($definition);
    }

    protected function assertKeyIsUnique(string $key, int $categoryId, ?int $ignoreId = null): void
    {
        if ($this->repository->keyExists($key, $categoryId, $ignoreId)) {
            throw ValidationException::withMessages([
                'key' => ["The key '{$key}' is already used in this category."],
            ]);
        }
    }

    /**
     * Parses an admin-submitted textarea of "key|Label" lines into the
     * structured options array stored on the definition.
     */
    protected function parseOptions(string $raw): array
    {
        $options = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$key, $label] = array_pad(explode('|', $line, 2), 2, null);
            $options[] = [
                'key' => Str::slug(trim($key), '_'),
                'label' => trim($label ?? $key),
            ];
        }

        return $options;
    }
}
