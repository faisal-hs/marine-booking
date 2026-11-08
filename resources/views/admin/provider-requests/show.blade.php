@extends('admin.layout')

@section('title', __('admin.provider_requests.details_title'))
@section('page_title', $providerRequest->provider->business_name)

@section('content')
    @php
        $provider = $providerRequest->provider;
        $applicant = $providerRequest->user;
        $variant = match ($providerRequest->status) {
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'warning',
        };
        $documentTitles = app(\App\Services\VerificationDocumentTypes::class)->titles();
        $required = app(\App\Services\VerificationDocumentTypes::class)->mandatoryKeys();
        // The three required documents first, then anything else the provider has on file.
        $documents = $provider->documents->sortBy(fn ($d) => in_array($d->type, $required, true) ? array_search($d->type, $required) : 99)->values();
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.provider_requests.applicant') }}</h2>
                <dl class="text-sm grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.dashboard.name') }}</dt>
                        <dd><x-admin.account-name :user="$applicant" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.phone') }}</dt>
                        <dd>{{ $applicant->phone }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.email') }}</dt>
                        <dd>{{ $applicant->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.provider_requests.source') }}</dt>
                        <dd>{{ __('admin.provider_requests.sources.' . $providerRequest->source) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.provider_requests.business') }}</h2>
                <dl class="text-sm grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.business_name') }}</dt>
                        <dd>{{ $provider->business_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.city') }}</dt>
                        <dd>{{ $provider->city ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.area') }}</dt>
                        <dd>{{ $provider->area ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.address') }}</dt>
                        <dd>{{ $provider->address ?? '—' }}</dd>
                    </div>
                    @if ($provider->bio)
                        <div class="sm:col-span-2">
                            <dt class="text-slate-400">{{ __('admin.providers.bio') }}</dt>
                            <dd class="whitespace-pre-line">{{ $provider->bio }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.provider_requests.documents') }}</h2>
                @forelse ($documents as $document)
                    <div class="flex items-center justify-between py-2 border-b border-slate-50 text-sm">
                        <div>
                            <p class="text-slate-800">{{ $documentTitles[$document->type] ?? $document->type }}</p>
                            <p class="text-xs text-slate-400">{{ $document->original_name }} · {{ number_format($document->size_bytes / 1024, 0) }} KB · {{ $document->created_at->format('Y-m-d') }}</p>
                        </div>
                        <x-admin.button pill variant="info" href="{{ route('admin.provider-requests.documents.download', [$providerRequest, $document]) }}">{{ __('admin.provider_requests.download') }}</x-admin.button>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">{{ __('admin.provider_requests.no_documents') }}</p>
                @endforelse
                @foreach ($required as $type)
                    @unless ($documents->contains('type', $type))
                        <p class="mt-2 text-xs text-red-600">{{ __('admin.provider_requests.missing', ['type' => $documentTitles[$type] ?? $type]) }}</p>
                    @endunless
                @endforeach
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.provider_requests.decision') }}</h2>
                <x-admin.status-badge :variant="$variant" :label="__('admin.provider_requests.statuses.' . $providerRequest->status)" />
                <p class="text-xs text-slate-400 mt-3">{{ __('admin.provider_requests.submitted') }}: {{ $providerRequest->submitted_at->format('Y-m-d H:i') }}</p>

                @if ($providerRequest->reviewed_at)
                    <p class="text-xs text-slate-400 mt-1">{{ __('admin.provider_requests.reviewed') }}: {{ $providerRequest->reviewed_at->format('Y-m-d H:i') }}@if ($providerRequest->reviewer) · {{ $providerRequest->reviewer->name }}@endif</p>
                @endif

                @if ($providerRequest->status === 'rejected' && $providerRequest->rejection_reason)
                    <p class="mt-3 text-sm text-slate-700 whitespace-pre-line"><span class="text-slate-400">{{ __('admin.provider_requests.reason') }}:</span> {{ $providerRequest->rejection_reason }}</p>
                @endif

                @if ($providerRequest->isPending())
                    @can('review', $providerRequest)
                        <div class="mt-5 space-y-4">
                            <x-admin.confirm-form :action="route('admin.provider-requests.approve', $providerRequest)" :confirm="__('admin.provider_requests.confirm_approve')" variant="success">{{ __('admin.provider_requests.approve') }}</x-admin.confirm-form>

                            <form method="POST" action="{{ route('admin.provider-requests.reject', $providerRequest) }}" class="space-y-2 text-sm">
                                @csrf
                                <x-admin.field name="rejection_reason" type="textarea" rows="3" :label="__('admin.provider_requests.reason')" :help="__('admin.provider_requests.reason_help')" required />
                                <button type="submit" class="bg-red-600 text-white rounded-lg px-4 py-2 hover:bg-red-700 transition">{{ __('admin.provider_requests.reject') }}</button>
                            </form>
                        </div>
                    @endcan
                @endif

                <div class="mt-5">
                    @can('providers.view')
                        <a href="{{ route('admin.providers.show', $provider) }}" class="text-xs text-slate-500 underline">{{ __('admin.provider_requests.open_provider') }}</a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection
