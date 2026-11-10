<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Models\City;
use App\Models\Provider;
use App\Models\Reservation;
use App\Exceptions\ProviderApiException;
use App\Services\CustomerApp\CancellationPolicy;
use Illuminate\Http\Request;

/** The pages, support details, FAQs and "about" screen. Public, in the language of the request. */
class ContentController extends CustomerAppController
{
    public function __construct(private readonly CancellationPolicy $policy) {}

    public function cancellationPolicy()
    {
        $titles = (array) __('customer_app.cancellation.titles');
        $kinds = ['full', 'partial', 'none'];

        // One section for each rule the cancel itself applies, so the page is the policy and not a description of it.
        $sections = [];
        foreach ($this->policy->rules() as $rule) {
            $kind = $rule['refund_percent'] >= 100 ? 'full' : ($rule['refund_percent'] > 0 ? 'partial' : 'none');
            $sections[] = ['title' => $titles[$kind], 'body' => $rule['text']];
        }
        $sections[] = ['title' => __('customer_app.cancellation.how.title'), 'body' => __('customer_app.cancellation.how.body')];

        return $this->ok(['title' => __('customer_app.pages.cancellation-policy.title'), 'updated_at' => config('customer_app.content_updated_at'), 'sections' => $sections]);
    }

    public function legal(string $slug)
    {
        abort_unless(in_array($slug, ['terms', 'privacy'], true), 404);

        $page = __("customer_app.pages.{$slug}");

        return $this->ok(['title' => $page['title'], 'updated_at' => config('customer_app.content_updated_at'), 'sections' => $page['sections']]);
    }

    public function settings()
    {
        $s = config('customer_app.support');

        return $this->ok(['support_phone' => $s['phone'] ?: null, 'support_email' => $s['email'] ?: null, 'whatsapp' => $s['whatsapp'] ?: null]);
    }

    /** Only the FAQs of a known category can be asked for; the answers that mention the cancellation rules are written from the real rules. */
    public function faqs(Request $request)
    {
        $faqs = array_values((array) __('customer_app.faqs'));
        $categories = array_values(array_unique(array_column($faqs, 'category')));
        $request->validate(['category' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $categories)]]);

        $policy = collect($this->policy->rules())->pluck('text')->implode(' ');

        return $this->ok(collect($faqs)->when($request->filled('category'), fn ($c) => $c->where('category', $request->input('category')))
            ->values()->map(fn ($f, $i) => ['id' => $i + 1, 'category' => $f['category'], 'question' => $f['question'], 'answer' => str_replace(':full_policy', $policy, $f['answer'])])->all());
    }

    /** @param  Request  $request  `platform` (ios or android) and the app's own `version` decide the version block */
    public function about(Request $request)
    {
        $request->validate(['platform' => ['sometimes', 'nullable', 'in:ios,android'], 'version' => ['sometimes', 'nullable', 'string', 'max:20']]);
        $platform = $request->input('platform', 'android');
        $v = config("customer_app.about.app_version.{$platform}");

        return $this->ok([
            'mission' => __('customer_app.about.mission'),
            'founded_year' => (int) config('customer_app.about.founded_year'),
            'providers_count' => Provider::where('verification_status', 'verified')->count(),
            'bookings_count' => Reservation::whereIn('status', ['confirmed', 'completed'])->count(),
            'cities_count' => City::where('is_active', true)->count(),
            'app_version' => [
                'latest' => $v['latest'],
                'minimum' => $v['minimum'],
                // Only when the app says which version it is can it be told to update.
                'force_update' => $request->filled('version') && version_compare($request->input('version'), $v['minimum'], '<'),
                'store_url' => $v['store_url'] ?: null,
            ],
        ]);
    }
}
