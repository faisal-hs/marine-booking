<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\UpdateNotificationPreferencesRequest;
use App\Models\Provider;
use Illuminate\Http\Request;

/**
 * How the provider wants to be told things: by email, by SMS, by WhatsApp, and whether they want news and offers. Open to a provider who is not approved yet too, like the
 * rest of onboarding: an applicant can say how they want to be reached.
 *
 * These are the provider's choices, kept for the channels to read. (They are stored, as a customer's own preferences are; nothing sends WhatsApp yet.)
 */
class NotificationPreferenceController extends ProviderAppController
{
    public function show(Request $request)
    {
        return $this->ok($this->describe($this->provider($request)));
    }

    public function update(UpdateNotificationPreferencesRequest $request)
    {
        $provider = $this->provider($request);
        $provider->update($request->changes());

        return $this->ok($this->describe($provider->refresh()), 'Notification preferences updated');
    }

    /** @return array{email: bool, sms: bool, whatsapp: bool, news_and_offers: bool} */
    private function describe(Provider $provider): array
    {
        return [
            'email' => (bool) $provider->notify_email,
            'sms' => (bool) $provider->notify_sms,
            'whatsapp' => (bool) $provider->notify_whatsapp,
            'news_and_offers' => (bool) $provider->notify_news_and_offers,
        ];
    }
}
