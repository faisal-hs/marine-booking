@extends('admin.layout')

@section('title', __('admin.providers.details_title'))
@section('page_title', $provider->business_name)

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            @if ($pendingChange = $provider->pendingProfileChange)
                <div class="bg-amber-50 rounded-xl border border-amber-200 p-6">
                    <h2 class="text-sm font-semibold text-slate-800 mb-1">{{ __('admin.providers.profile_change.title') }}</h2>
                    <p class="text-xs text-slate-600 mb-4">{{ __('admin.providers.profile_change.intro') }}</p>
                    <dl class="text-sm space-y-3 mb-4">
                        @foreach ($pendingChange->requested_changes as $field => $change)
                            <div>
                                <dt class="text-slate-400">{{ trans()->has('admin.providers.profile_change.fields.'.$field) ? __('admin.providers.profile_change.fields.'.$field) : \Illuminate\Support\Str::headline($field) }}</dt>
                                <dd><span class="text-slate-500">{{ __('admin.providers.profile_change.current') }}:</span> {{ $change['from'] ?? '—' }}</dd>
                                <dd class="font-medium"><span class="text-slate-500 font-normal">{{ __('admin.providers.profile_change.requested') }}:</span> {{ $change['to'] }}</dd>
                            </div>
                        @endforeach
                        <div class="text-xs text-slate-500">{{ __('admin.providers.profile_change.requested_at') }}: {{ $pendingChange->created_at->toDateTimeString() }}</div>
                    </dl>
                    @can('update', $provider)
                        <div class="flex flex-wrap gap-3 items-start">
                            <form method="POST" action="{{ route('admin.providers.profile-changes.approve', [$provider, $pendingChange]) }}">
                                @csrf
                                <button type="submit" class="bg-emerald-600 text-white rounded-lg px-3 py-1.5 text-sm">{{ __('admin.providers.profile_change.approve') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.providers.profile-changes.reject', [$provider, $pendingChange]) }}"
                                onsubmit="return confirm('{{ __('admin.providers.profile_change.confirm_reject') }}')" class="flex gap-2">
                                @csrf
                                <input type="text" name="rejection_reason" required placeholder="{{ __('admin.providers.profile_change.reason_placeholder') }}"
                                    class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm w-64">
                                <button type="submit" class="bg-red-600 text-white rounded-lg px-3 py-1.5 text-sm">{{ __('admin.providers.profile_change.reject') }}</button>
                            </form>
                        </div>
                    @endcan
                </div>
            @endif

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.details_title') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.business_name') }}</dt>
                        <dd>{{ $provider->business_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.customer_name') }}</dt>
                        <dd><x-admin.account-name :user="$provider->user" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.email') }}</dt>
                        <dd>{{ $provider->user->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.phone') }}</dt>
                        <dd>{{ $provider->user->phone ?? '—' }}</dd>
                    </div>
                    @if ($provider->business_license)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.providers.business_license') }}</dt>
                            <dd>{{ $provider->business_license }}</dd>
                        </div>
                    @endif
                    @if ($provider->address)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.providers.address') }}</dt>
                            <dd>{{ $provider->address }}</dd>
                        </div>
                    @endif
                    @if ($provider->city || $provider->area)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.providers.city') }} / {{ __('admin.providers.area') }}</dt>
                            <dd>{{ collect([$provider->city, $provider->area])->filter()->implode(' · ') }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.request_title') }}</dt>
                        <dd>
                            @if ($provider->latestRequest)
                                {{ __('admin.provider_requests.statuses.' . $provider->latestRequest->status) }}
                                @can('provider_requests.view')
                                    · <a href="{{ route('admin.provider-requests.show', $provider->latestRequest) }}" class="underline">{{ __('admin.providers.open_request') }}</a>
                                @endcan
                            @else
                                {{ __('admin.providers.request_none') }}
                            @endif
                        </dd>
                    </div>
                    @if ($provider->bio)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.providers.bio') }}</dt>
                            <dd>{{ $provider->bio }}</dd>
                        </div>
                    @endif
                    @if ($provider->rejection_reason)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.providers.rejection_reason') }}</dt>
                            <dd>{{ $provider->rejection_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @php
                $titles = app(\App\Services\VerificationDocumentTypes::class)->titles();
                $activeKeys = $documentRows->map(fn ($row) => $row['type']->key)->all();
                $otherDocuments = $provider->documents->reject(fn ($d) => in_array($d->type, $activeKeys, true));
                $canReview = auth()->user()->can('review', \App\Models\ProviderDocument::class);
            @endphp
            <div id="documents" class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.documents_title') }}</h2>

                @if ($provider->documents->isEmpty())
                    <p class="text-sm mb-2 {{ $provider->verification_status === 'pending' ? 'text-amber-600' : 'text-slate-400' }}">{{ __('admin.providers.no_documents') }}</p>
                @endif

                @foreach ($documentRows as $row)
                    @php
                        $type = $row['type'];
                        $document = $row['document'];
                        $status = $row['status'];
                        $variant = match ($status) { 'approved' => 'success', 'rejected', 'expired' => 'danger', 'pending' => 'warning', 'update_requested' => 'info', default => 'neutral' };
                    @endphp
                    <div class="py-3 border-b border-slate-100 text-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-slate-800">
                                    {{ $type->title() }}
                                    <x-admin.status-badge :variant="$variant" :label="__('admin.verification_documents.statuses.' . $status)" />
                                    @if ($type->is_mandatory)
                                        <x-admin.status-badge variant="warning" :label="__('admin.document_types.mandatory')" />
                                    @endif
                                </p>
                                @if ($document)
                                    <p class="text-xs text-slate-400">
                                        @if ($document->title) {{ $document->title }} · @endif{{ $document->original_name }} · {{ number_format($document->size_bytes / 1024, 0) }} KB · {{ $document->created_at->toFormattedDateString() }}
                                        @if ($document->expires_at) · {{ __('admin.verification_documents.expires') }} {{ $document->expires_at->toFormattedDateString() }} @endif
                                    </p>
                                    @if ($document->status === 'rejected' && $document->review_note)
                                        <p class="text-xs text-red-600 mt-1">{{ $document->review_note }}</p>
                                    @endif
                                @else
                                    <p class="text-xs {{ $type->is_mandatory && $provider->verification_status === 'pending' ? 'text-amber-600' : 'text-slate-400' }}">{{ __('admin.verification_documents.not_uploaded') }}</p>
                                @endif
                                @if ($row['request'])
                                    <p class="text-xs text-sky-700 mt-1">{{ __('admin.verification_documents.update_waiting') }}: {{ $row['request']->note }}</p>
                                @endif
                            </div>
                            @if ($document)
                                <a href="{{ route('admin.providers.documents.download', ['provider' => $provider, 'document' => $document->uuid]) }}"
                                    class="text-xs text-slate-500 underline whitespace-nowrap">{{ __('admin.providers.download') }}</a>
                            @endif
                        </div>

                        @if ($canReview)
                            <div class="mt-2 flex flex-wrap items-start gap-3">
                                @if ($document && $document->status === 'pending')
                                    <form method="POST" action="{{ route('admin.providers.documents.approve', ['provider' => $provider, 'document' => $document->uuid]) }}" class="flex gap-2">
                                        @csrf
                                        <input type="date" name="expires_at" title="{{ __('admin.verification_documents.expires_optional') }}" class="border border-slate-200 rounded-lg px-2 py-1 text-xs">
                                        <button type="submit" class="bg-emerald-600 text-white rounded-lg px-3 py-1 text-xs">{{ __('admin.verification_documents.approve') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.providers.documents.reject', ['provider' => $provider, 'document' => $document->uuid]) }}" class="flex gap-2">
                                        @csrf
                                        <input type="text" name="note" required placeholder="{{ __('admin.verification_documents.reason_placeholder') }}" class="border border-slate-200 rounded-lg px-2 py-1 text-xs">
                                        <button type="submit" class="bg-red-600 text-white rounded-lg px-3 py-1 text-xs">{{ __('admin.verification_documents.reject') }}</button>
                                    </form>
                                @endif
                                @unless ($row['request'])
                                    <form method="POST" action="{{ route('admin.providers.documents.request-update', $provider) }}" class="flex gap-2">
                                        @csrf
                                        <input type="hidden" name="document_type" value="{{ $type->key }}">
                                        <input type="text" name="reason" required placeholder="{{ __('admin.verification_documents.reason_placeholder') }}" class="border border-slate-200 rounded-lg px-2 py-1 text-xs">
                                        <button type="submit" class="bg-sky-600 text-white rounded-lg px-3 py-1 text-xs">{{ __('admin.verification_documents.request_update') }}</button>
                                    </form>
                                @endunless
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($otherDocuments->isNotEmpty())
                    <h3 class="text-xs font-semibold text-slate-500 mt-4 mb-2">{{ __('admin.verification_documents.other_documents') }}</h3>
                    <ul class="text-sm divide-y divide-slate-100">
                        @foreach ($otherDocuments as $document)
                            <li class="py-2 flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-slate-800">{{ $document->title ?: ($titles[$document->type] ?? $document->type) }}</p>
                                    <p class="text-xs text-slate-400">{{ $titles[$document->type] ?? $document->type }} · {{ $document->original_name }} · {{ $document->created_at->toFormattedDateString() }}</p>
                                </div>
                                <a href="{{ route('admin.providers.documents.download', ['provider' => $provider, 'document' => $document->uuid]) }}" class="text-xs text-slate-500 underline whitespace-nowrap">{{ __('admin.providers.download') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($documentHistory->isNotEmpty())
                    <h3 class="text-xs font-semibold text-slate-500 mt-5 mb-2">{{ __('admin.verification_documents.history') }}</h3>
                    <ul class="text-xs text-slate-600 space-y-1">
                        @foreach ($documentHistory as $event)
                            <li>
                                <span class="text-slate-400">{{ $event->created_at?->format('Y-m-d H:i') }}</span>
                                · {{ $titles[$event->document_type] ?? $event->document_type }}
                                · <strong>{{ __('admin.verification_documents.events.' . $event->action) }}</strong>
                                @if ($event->actor) · {{ $event->actor->name }} @endif
                                @if ($event->note) · {{ $event->note }} @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.summary_title') }}</h2>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ $summary['bookings_total'] }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.providers.bookings_total') }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ $summary['bookings_live'] }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.providers.bookings_live') }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ number_format($summary['gross_revenue'], 2) }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.providers.gross_revenue', ['currency' => config('platform.currency')]) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.activities_title') }}</h2>

                @forelse ($activities as $activity)
                    @php
                        $activityVariant = match ($activity->status) {
                            'published' => 'success',
                            'inactive' => 'neutral',
                            default => 'info',
                        };
                    @endphp
                    <div class="flex items-center justify-between py-2 border-b border-slate-50 text-sm">
                        <div>
                            @can('activities.view')
                                <a href="{{ route('admin.activities.show', $activity) }}" class="text-slate-800 underline">{{ $activity->title }}</a>
                            @else
                                <span class="text-slate-800">{{ $activity->title }}</span>
                            @endcan
                            <p class="text-xs text-slate-400">{{ $activity->category?->name }}</p>
                        </div>
                        <x-admin.status-badge :variant="$activityVariant" :label="__('admin.activities.statuses.' . $activity->status)" />
                    </div>
                @empty
                    <p class="text-sm text-slate-400">{{ __('admin.providers.no_activities') }}</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.status') }}</h2>
                @php
                    $variant = match ($provider->verification_status) {
                        'verified' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'info',
                    };
                @endphp
                <x-admin.status-badge :variant="$variant" :label="__('admin.providers.statuses.' . $provider->verification_status)" />

                @if ($provider->verification_status === 'pending')
                    <div class="mt-4 space-y-2">
                        @can('verify', \App\Models\Provider::class)
                            <form method="POST" action="{{ route('admin.providers.verify', $provider) }}">
                                @csrf
                                <button type="submit" class="w-full bg-emerald-600 text-white rounded-lg px-3 py-1.5 text-sm">
                                    {{ __('admin.providers.verify') }}
                                </button>
                            </form>
                        @endcan

                        @can('reject', \App\Models\Provider::class)
                            <form method="POST" action="{{ route('admin.providers.reject', $provider) }}"
                                onsubmit="return confirm('{{ __('admin.providers.confirm_reject') }}')">
                                @csrf
                                <input type="text" name="rejection_reason"
                                    placeholder="{{ __('admin.providers.rejection_reason_placeholder') }}" required
                                    class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm mb-2">
                                <button type="submit" class="w-full bg-red-600 text-white rounded-lg px-3 py-1.5 text-sm">
                                    {{ __('admin.providers.reject') }}
                                </button>
                            </form>
                        @endcan
                    </div>
                @endif
                        </div>

            @php
                $deleted = $provider->user->trashed();
            @endphp
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.account_title') }}</h2>

                @if ($deleted)
                    <x-admin.status-badge variant="neutral" :label="__('admin.accounts.deleted')" />
                @else
                    <x-admin.status-badge :variant="$provider->user->status === 'active' ? 'success' : 'danger'" :label="__('admin.statuses.' . $provider->user->status)" />
                @endif

                <p class="text-xs text-slate-400 mt-3">
                    {{ __('admin.providers.joined') }}: {{ $provider->created_at->toFormattedDateString() }}
                    · {{ __('admin.providers.commission') }}: {{ $provider->commission_type === 'fixed' ? number_format((float) $provider->commission_rate, 2).' '.config('platform.currency') : $provider->commission_rate.'%' }}
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($deleted)
                        @can('restore', $provider)
                            <x-admin.confirm-form :action="route('admin.providers.restore', $provider)" variant="success">{{ __('admin.providers.restore') }}</x-admin.confirm-form>
                        @endcan
                    @else
                        @can('update', $provider)
                            <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.providers.edit', $provider) }}">{{ __('admin.providers.edit') }}</x-admin.button>
                            @if ($provider->user->status === 'active')
                                <x-admin.confirm-form :action="route('admin.providers.suspend', $provider)" :confirm="__('admin.providers.confirm_suspend')" variant="danger">{{ __('admin.providers.suspend') }}</x-admin.confirm-form>
                            @else
                                <x-admin.confirm-form :action="route('admin.providers.reactivate', $provider)" variant="success">{{ __('admin.providers.reactivate') }}</x-admin.confirm-form>
                            @endif
                        @endcan
                        @can('delete', $provider)
                            <x-admin.confirm-form :action="route('admin.providers.destroy', $provider)" method="DELETE" :confirm="__('admin.providers.confirm_delete')" variant="danger" icon="trash">{{ __('admin.providers.delete') }}</x-admin.confirm-form>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
