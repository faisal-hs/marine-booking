<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\ApplyAsProviderRequest;
use App\Http\Requests\ProviderApp\ResubmitApplicationRequest;
use App\Models\ProviderRequest;
use App\Services\ProviderRequestService;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The provider application (spec phase 1). It is the same application an admin reviews on the Provider requests screen: submitting creates
 * one request, a rejection leaves the old request in place as history, and resubmitting makes a NEW request, so the review history is never lost.
 *
 * Open to a provider who is not approved yet. (A customer who wants to become a provider uses POST /account/provider-request with a customer token.)
 */
class ApplicationController extends ProviderAppController
{
    private const REQUIRED_AFTER_MERGE = ['business_name' => 'business_name', 'business_type' => 'business_type', 'category_ids' => 'category_ids', 'city' => 'city',
        'area' => 'area', 'marina_name' => 'marina_name', 'contact_phone' => 'phone', 'contact_email' => 'email'];

    public function __construct(private readonly ProviderRequestService $applications) {}

    /** The latest application and what the admin said about it. */
    public function show(Request $request)
    {
        $latest = $this->provider($request)?->latestRequest;

        if (! $latest) {
            throw ProviderApiException::notFound('You have not submitted an application yet.');
        }

        return $this->ok($this->describe($latest));
    }

    public function store(ApplyAsProviderRequest $request)
    {
        $provider = $request->user()->provider;

        if ($provider?->isVerified()) {
            throw ProviderApiException::conflict('You already have an approved provider account.', ErrorCode::INVALID_STATUS_TRANSITION);
        }
        if ($provider?->latestRequest?->isPending()) {
            throw ProviderApiException::conflict('Your application is already waiting for approval.', ErrorCode::INVALID_STATUS_TRANSITION);
        }

        $application = $this->applications->submit($request->user(), $request->details(), $request->documents());

        return $this->created(['application_id' => $application->id, 'status' => 'pending', 'submitted_at' => $application->submitted_at->utc()->format('Y-m-d\TH:i:s\Z')], 'Application submitted');
    }

    /** Only the LATEST application, and only when it was rejected. What is not sent keeps its previous value. */
    public function resubmit(ResubmitApplicationRequest $request)
    {
        $user = $request->user();
        $provider = $user->provider;
        $latest = $provider?->latestRequest;

        if (! $latest) {
            throw ProviderApiException::notFound('You have not submitted an application yet.');
        }
        if ($latest->id !== $request->integer('application_id')) {
            throw ProviderApiException::conflict('Only your latest application can be resubmitted.', ErrorCode::INVALID_STATUS_TRANSITION);
        }
        if ($latest->status !== 'rejected') {
            throw ProviderApiException::conflict('Only a rejected application can be resubmitted.', ErrorCode::INVALID_STATUS_TRANSITION);
        }

        $data = array_merge([
            'business_name' => $provider->business_name,
            'business_type' => $provider->business_type,
            'category_ids' => $provider->categories()->pluck('categories.id')->all(),
            'city' => $provider->city,
            'area' => $provider->area,
            'marina_name' => $provider->marina_name,
            'bio' => $provider->bio,
            'address' => $provider->address,
            'contact_phone' => $provider->contact_phone,
            'contact_email' => $provider->contact_email,
        ], $request->changes());

        // An older provider may have no business type, marina, contact or categories on file: then they have to be sent now.
        $missing = [];
        foreach (self::REQUIRED_AFTER_MERGE as $column => $field) {
            if (empty($data[$column])) {
                $missing[$field] = ['This field is required.'];
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $application = $this->applications->submit($user, $data, $request->documents());

        return $this->created(['application_id' => $application->id, 'previous_application_id' => $latest->id, 'status' => 'pending'], 'Application resubmitted');
    }

    /** @return array<string, mixed> */
    private function describe(ProviderRequest $application): array
    {
        $rejected = $application->status === 'rejected';

        return [
            'application_id' => $application->id,
            'status' => $application->status,
            'note' => $rejected ? $application->rejection_reason : null,
            'submitted_at' => $application->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'reviewed_at' => $application->reviewed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            // Only a rejected application can be sent again.
            'can_resubmit' => $rejected,
        ];
    }
}
