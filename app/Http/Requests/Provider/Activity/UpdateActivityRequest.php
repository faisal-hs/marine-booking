<?php

namespace App\Http\Requests\Provider\Activity;

use App\Enums\BookingModel;
use App\Enums\ParticipantType;
use App\Models\ActivitySchedulePeriod;
use App\Services\ActivityFeatureService;
use App\Http\Requests\Provider\Activity\Concerns\HasPaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateActivityRequest extends FormRequest
{
    use HasPaymentMethods;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePaymentMethods();

        if (! $this->has('min_participants')) {
            $this->merge([
                'min_participants' => $this->route('activity')?->min_participants ?? 1,
            ]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where('status', 'active')],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_point' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_participants' => ['integer', 'min:1'],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'gte:min_participants'],
            'min_age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],

            // Which buy-now-pay-later methods the provider accepts for this activity.
            ...$this->paymentMethodRules(),

            'images' => ['sometimes', 'nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:4096'],

            // Photos in the same request: `new_images` are added (the older name `images` means the same), and `deleted_image_ids` are the ids of this
            // activity's photos to remove. Both are optional, and null means "not sent". The activity can end up with at most 10 photos.
            'new_images' => ['sometimes', 'nullable', 'array', 'max:10'],
            'new_images.*' => ['image', 'max:4096'],
            'deleted_image_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'deleted_image_ids.*' => ['integer', 'distinct'],

            'features' => ['sometimes', 'nullable', 'array'],

            'options' => ['sometimes', 'nullable', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_required' => ['boolean'],
            'options.*.max_quantity' => ['nullable', 'integer', 'min:1'],

            'requirements' => ['sometimes', 'nullable', 'array'],
            'requirements.*.text' => ['required_with:requirements', 'string', 'max:255'],
            'requirements.*.is_mandatory' => ['boolean'],

            'inclusions' => ['sometimes', 'nullable', 'array'],
            'inclusions.*.type' => ['required_with:inclusions', Rule::in(['included', 'excluded'])],
            'inclusions.*.description' => ['required_with:inclusions', 'string', 'max:255'],

            // NOTE: no 'schedule.*.price' rule — see StoreActivityRequest
            // for why this was removed here as part of Module 08 Step 5.
            'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.*.day_of_week' => ['required_with:schedule', 'string', Rule::in(array_values(ActivitySchedulePeriod::dayNames()))],
            'schedule.*.start_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.end_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.instructions' => ['nullable', 'string', 'max:1000'],
            'schedule.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],

            'booking_options' => ['sometimes', 'nullable', 'array'],
            'booking_options.*.name' => ['required_with:booking_options', 'string', 'max:255'],
            'booking_options.*.description' => ['nullable', 'string'],
            'booking_options.*.booking_model' => ['required_with:booking_options', 'string', Rule::in(array_map(fn($c) => $c->value, BookingModel::cases()))],
            'booking_options.*.min_participants' => ['nullable', 'integer', 'min:1'],
            'booking_options.*.max_participants' => ['nullable', 'integer'],
            'booking_options.*.min_quantity' => ['nullable', 'integer', 'min:1'],
            'booking_options.*.max_quantity' => ['nullable', 'integer'],
            'booking_options.*.min_duration' => ['nullable', 'integer', 'min:1'],
            'booking_options.*.max_duration' => ['nullable', 'integer'],
            'booking_options.*.duration_unit' => ['nullable', 'string', Rule::in(['hour', 'day'])],
            'booking_options.*.is_active' => ['sometimes', 'boolean'],
            'booking_options.*.is_default' => ['sometimes', 'boolean'],
            'booking_options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'booking_options.*.pricing_rules' => ['sometimes', 'nullable', 'array'],
            'booking_options.*.pricing_rules.*.participant_type' => ['nullable', 'string', Rule::in(array_map(fn($c) => $c->value, ParticipantType::cases()))],
            'booking_options.*.pricing_rules.*.price' => ['required', 'numeric', 'min:0'],
            'booking_options.*.pricing_rules.*.min_quantity' => ['nullable', 'integer', 'min:1'],
            'booking_options.*.pricing_rules.*.max_quantity' => ['nullable', 'integer'],
            'booking_options.*.pricing_rules.*.schedule_period_id' => ['nullable', 'integer'],
            'booking_options.*.pricing_rules.*.currency' => ['nullable', 'string', 'size:3'],
            'booking_options.*.pricing_rules.*.is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->filled('features')) {
            $categoryId = $this->filled('category_id')
                ? (int) $this->input('category_id')
                : $this->route('activity')?->category_id;

            if ($categoryId) {
                $rules = array_merge($rules, app(ActivityFeatureService::class)->buildValidationRulesForCategory($categoryId));
            }
        }

        return $rules;
    }

    /** Photos an activity can have, in all. */
    public const MAX_IMAGES = 10;

    /** @return list<\Illuminate\Http\UploadedFile> every new photo sent, under either name */
    public function newImageFiles(): array
    {
        return array_values(array_filter(array_merge(\Illuminate\Support\Arr::wrap($this->file('images')), \Illuminate\Support\Arr::wrap($this->file('new_images')))));
    }

    /** @return list<int> the photos to delete */
    public function deletedImageIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) ($this->input('deleted_image_ids') ?? []), 'is_numeric'))));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->checkPhotos($validator);

            foreach ((array) ($this->input('booking_options') ?? []) as $index => $option) {
                $model = $option['booking_model'] ?? null;

                if (in_array($model, ['per_hour', 'per_day', 'package'], true) && empty($option['min_duration'])) {
                    $validator->errors()->add("booking_options.{$index}.min_duration", 'The min_duration field is required for this booking model.');
                }

                if (in_array($model, ['per_hour', 'per_day'], true) && empty($option['max_duration'])) {
                    $validator->errors()->add("booking_options.{$index}.max_duration", 'The max_duration field is required for this booking model.');
                }
            }
        });
    }

    /** Only this activity's own photos can be deleted (a foreign or made-up id is refused, not skipped), and the activity ends with at most MAX_IMAGES. */
    private function checkPhotos(Validator $validator): void
    {
        $activity = $this->route('activity');
        $asked = (array) ($this->input('deleted_image_ids') ?? []);
        $own = $activity->images()->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($asked as $i => $id) {
            if (is_numeric($id) && ! in_array((int) $id, $own, true) && ! $validator->errors()->has("deleted_image_ids.{$i}")) {
                $validator->errors()->add("deleted_image_ids.{$i}", 'This is not a photo of this activity.');
            }
        }

        $after = count($own) - count(array_intersect($this->deletedImageIds(), $own)) + count($this->newImageFiles());
        if ($after > self::MAX_IMAGES && ! $validator->errors()->has('new_images')) {
            $validator->errors()->add('new_images', 'An activity can have at most '.self::MAX_IMAGES." photos; this would leave it with {$after}.");
        }
    }

    public function payload(): array
    {
        return $this->paymentMethodPayload() + $this->only([
            'category_id',
            'title',
            'description',
            'location',
            'meeting_point',
            'lat',
            'lng',
            'duration_minutes',
            'min_participants',
            'max_participants',
            'min_age',
        ]);
    }

    public function scheduleItems(): array
    {
        return collect($this->input('schedule') ?? [])->map(function (array $item) {
            $item['day_of_week'] = ActivitySchedulePeriod::dayNumberFromName($item['day_of_week']);

            return $item;
        })->all();
    }

    public function bookingOptionItems(): array
    {
        return collect($this->input('booking_options') ?? [])->map(function (array $item) {
            return [
                'data' => collect($item)->except('pricing_rules')->all(),
                'pricing_rules' => $item['pricing_rules'] ?? [],
            ];
        })->all();
    }
}
