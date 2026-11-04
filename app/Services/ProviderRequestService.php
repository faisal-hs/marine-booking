<?php

namespace App\Services;

use App\Events\ProviderRequestSubmitted;
use App\Models\Provider;
use App\Models\ProviderRequest;
use App\Models\User;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Becoming a provider takes an admin's approval.
 *
 * Two people use this: a CUSTOMER who wants to start offering activities, and someone who REGISTERED
 * as a provider. Both submit the same thing (business name, city, area and three documents) and wait.
 * Until an admin approves it the account can do nothing on the provider side (EnsureProviderApproved).
 *
 * The details and the documents live on the Provider row, created the first time a customer asks.
 * The ProviderRequest is the submission and its review. Approving or rejecting is done through
 * ProviderVerificationService so that the provider and its request can never disagree, whether the
 * decision comes from the requests screen or from the provider's own page.
 */
class ProviderRequestService
{
    public function __construct(
        protected ProviderRepositoryInterface $providers,
        protected ProviderDocumentService $documents,
        protected ProviderVerificationService $verification,
    ) {}

    /**
     * Submit a request, or resubmit one that was rejected.
     *
     * @param  array{business_name: string, city: string, area: string, bio?: ?string, address?: ?string}  $data
     * @param  array<string, UploadedFile|null>  $files  keyed by required document type; on a resubmission a type
     *                                                    may be left out if a document of it is already on file
     */
    public function submit(User $user, array $data, array $files): ProviderRequest
    {
        $provider = $user->provider;

        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['provider' => ['Staff accounts cannot become providers.']]);
        }

        if ($provider?->isVerified()) {
            throw ValidationException::withMessages(['provider' => ['You already have an approved provider account.']]);
        }

        if ($provider?->latestRequest?->isPending()) {
            throw ValidationException::withMessages(['provider' => ['Your provider request is already waiting for approval.']]);
        }

        $stored = [];

        try {
            $request = DB::transaction(function () use ($user, $data, $files, $provider, &$stored) {
                $source = $user->user_type === 'provider' ? ProviderRequest::SOURCE_REGISTRATION : ProviderRequest::SOURCE_CUSTOMER_UPGRADE;
                $provider = $this->saveProvider($user, $provider, $data);

                foreach (config('providers.documents.required_types') as $type) {
                    $file = $files[$type] ?? null;

                    if ($file instanceof UploadedFile) {
                        $stored[] = $this->documents->replace($provider, $type, $file)->path;
                    } elseif (! $provider->documents()->where('type', $type)->exists()) {
                        throw ValidationException::withMessages([$type => ['This document is required.']]);
                    }
                }

                return ProviderRequest::create([
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                    'source' => $source,
                    'status' => 'pending',
                    'submitted_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // The rows rolled back; do not leave the files of this attempt behind.
            Storage::disk(config('providers.documents.disk'))->delete($stored);

            throw $e;
        }

        $user->unsetRelation('provider');
        event(new ProviderRequestSubmitted($request));

        return $request;
    }

    /** An admin approves: the provider becomes verified and the person can use the provider account. */
    public function approve(ProviderRequest $request, User $reviewer): ProviderRequest
    {
        $this->assertPending($request);

        if ($request->user->trashed() || $request->user->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['This account is deleted or suspended, so its request cannot be approved.']]);
        }

        $this->verification->verify($request->provider, $reviewer);

        return $request->fresh();
    }

    /** An admin rejects, with a reason the applicant can read. They may fix things and submit again. */
    public function reject(ProviderRequest $request, User $reviewer, string $reason): ProviderRequest
    {
        $this->assertPending($request);

        $this->verification->reject($request->provider, $reason, $reviewer);

        return $request->fresh();
    }

    /** Creates the provider row on a customer's first request; updates the details on a later one. */
    protected function saveProvider(User $user, ?Provider $provider, array $data): Provider
    {
        $details = [
            'business_name' => $data['business_name'],
            'city' => $data['city'],
            'area' => $data['area'],
            'bio' => $data['bio'] ?? $provider?->bio,
            'address' => $data['address'] ?? $provider?->address,
            // Back to waiting, whether this is the first submission or a resubmission after a rejection.
            'verification_status' => 'pending',
            'rejection_reason' => null,
        ];

        if (! $provider) {
            return $this->providers->create($details + ['user_id' => $user->id, 'slug' => $this->providers->uniqueSlug($data['business_name'])]);
        }

        // An unapproved provider has nothing public yet, so a new business name can change its address.
        if ($provider->business_name !== $data['business_name']) {
            $details['slug'] = $this->providers->uniqueSlug($data['business_name']);
        }

        return $this->providers->update($provider, $details);
    }

    protected function assertPending(ProviderRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => ['This request has already been decided.']]);
        }
    }

    /**
     * The admin list. Filters: status (pending, approved, rejected, or all), source, search (business,
     * city, area, applicant name, phone or e-mail).
     */
    public function paginateForAdmin(array $filters, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = ProviderRequest::query()->with(['provider', 'user']);

        if (in_array($filters['status'] ?? null, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $filters['status']);
        }

        if (in_array($filters['source'] ?? null, [ProviderRequest::SOURCE_REGISTRATION, ProviderRequest::SOURCE_CUSTOMER_UPGRADE], true)) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(fn($q) => $q
                ->whereHas('provider', fn($p) => $p->where('business_name', 'like', $term)->orWhere('city', 'like', $term)->orWhere('area', 'like', $term))
                ->orWhereHas('user', fn($u) => $u->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term)));
        }

        return $query->latest('submitted_at')->latest('id')->paginate($perPage)->withQueryString();
    }

    /** @return array{pending: int, approved: int, rejected: int, all: int} */
    public function counts(): array
    {
        $byStatus = ProviderRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'all' => (int) $byStatus->sum(),
        ];
    }
}
