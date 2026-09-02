<?php

namespace App\Http\Requests\Provider\Activity;

use App\Enums\BookingModel;
use App\Enums\ParticipantType;
use App\Models\ActivitySchedulePeriod;
use App\Services\ActivityFeatureService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'min_participants' => $this->input('min_participants', 1),
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('status', 'active')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_point' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'min_participants' => ['integer', 'min:1'],
            'max_participants' => ['nullable', 'integer', 'gte:min_participants'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:120'],

            // --- Combined create: images, features, options, requirements,
            // inclusions, schedule, and booking_options are all optional on
            // top of the base activity fields above. Each is validated the
            // same way its dedicated standalone endpoint validates it
            // (Module 05/07/08).
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['image', 'max:4096'],

            'features' => ['sometimes', 'array'],

            'options' => ['sometimes', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_required' => ['boolean'],
            'options.*.max_quantity' => ['nullable', 'integer', 'min:1'],

            'requirements' => ['sometimes', 'array'],
            'requirements.*.text' => ['required_with:requirements', 'string', 'max:255'],
            'requirements.*.is_mandatory' => ['boolean'],

            'inclusions' => ['sometimes', 'array'],
            'inclusions.*.type' => ['required_with:inclusions', Rule::in(['included', 'excluded'])],
            'inclusions.*.description' => ['required_with:inclusions', 'string', 'max:255'],

            // NOTE: no 'schedule.*.price' rule — pricing moved to
            // ActivityBookingOption/ActivityPricingRule in Module 08.
            // This rule was mistakenly left in place after Module 08 Step 2
            // removed price from schedule periods everywhere else; fixed
            // here as part of Step 5.
            'schedule' => ['sometimes', 'array'],
            'schedule.*.day_of_week' => ['required_with:schedule', 'string', Rule::in(array_values(ActivitySchedulePeriod::dayNames()))],
            'schedule.*.start_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.end_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.instructions' => ['nullable', 'string', 'max:1000'],
            'schedule.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],

            'booking_options' => ['sometimes', 'array'],
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
            'booking_options.*.pricing_rules' => ['sometimes', 'array'],
            'booking_options.*.pricing_rules.*.participant_type' => ['nullable', 'string', Rule::in(array_map(fn($c) => $c->value, ParticipantType::cases()))],
            'booking_options.*.pricing_rules.*.price' => ['required', 'numeric', 'min:0'],
            'booking_options.*.pricing_rules.*.min_quantity' => ['nullable', 'integer', 'min:1'],
            'booking_options.*.pricing_rules.*.max_quantity' => ['nullable', 'integer'],
            'booking_options.*.pricing_rules.*.schedule_period_id' => ['nullable', 'integer'],
            'booking_options.*.pricing_rules.*.currency' => ['nullable', 'string', 'size:3'],
            'booking_options.*.pricing_rules.*.is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->filled('category_id') && $this->filled('features')) {
            $rules = array_merge(
                $rules,
                app(ActivityFeatureService::class)->buildValidationRulesForCategory((int) $this->input('category_id'))
            );
        }

        return $rules;
    }

    /**
     * Per-model duration requirements (matching StoreBookingOptionRequest's
     * own business rule exactly) implemented here rather than as a
     * declarative required_if rule — Laravel's required_if cannot reliably
     * reference "this same array item's own sibling field" across nested
     * wildcards (booking_options.*.booking_model as the condition for
     * booking_options.*.min_duration at the SAME index), so a manual
     * after-hook is the correct, unambiguous way to enforce this.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('booking_options', []) as $index => $option) {
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

    public function payload(): array
    {
        return $this->only([
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
        return collect($this->input('schedule', []))->map(function (array $item) {
            $item['day_of_week'] = ActivitySchedulePeriod::dayNumberFromName($item['day_of_week']);

            return $item;
        })->all();
    }

    /**
     * Splits each booking option's own attributes from its nested
     * pricing_rules array, since ActivityBookingOptionService::create()
     * only accepts the option's own fields.
     */
    public function bookingOptionItems(): array
    {
        return collect($this->input('booking_options', []))->map(function (array $item) {
            return [
                'data' => collect($item)->except('pricing_rules')->all(),
                'pricing_rules' => $item['pricing_rules'] ?? [],
            ];
        })->all();
    }
}
