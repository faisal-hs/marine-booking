<?php

namespace App\Http\Requests\ProviderApp;

/**
 * POST /provider/apply/resubmit: the id of the rejected application, and only what changes. Anything left out keeps its previous value
 * (the controller fills it in), so the rules are the same as an application's with every field optional.
 */
class ResubmitApplicationRequest extends ApplyAsProviderRequest
{
    public function rules(): array
    {
        $rules = ['application_id' => ['required', 'integer', 'min:1']];

        foreach (parent::rules() as $field => $rule) {
            if (in_array($field, config('providers.documents.required_types'), true)) {
                // Optional here: whether one is still needed is decided AFTER the state checks (is there a rejected application?), by the
                // application service ("required unless on file"), so a provider with no application gets a 404, not a complaint about a file.
                $rules[$field] = ['nullable', 'file', 'mimes:'.implode(',', config('providers.documents.mimes')), 'max:'.config('providers.documents.max_kb')];

                continue;
            }

            $rules[$field] = array_merge(['sometimes'], array_diff($rule, ['required']));
        }

        return $rules;
    }

    /** @return array<string, mixed> only what was sent */
    public function changes(): array
    {
        $data = $this->safe()->only(['business_name', 'business_type', 'category_ids', 'city', 'area', 'marina_name', 'bio', 'address']);

        foreach (['phone' => 'contact_phone', 'email' => 'contact_email'] as $input => $column) {
            if ($this->has($input)) {
                $data[$column] = $this->input($input);
            }
        }

        return $data;
    }
}
