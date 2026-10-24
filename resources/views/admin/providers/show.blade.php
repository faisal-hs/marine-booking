@extends('admin.layout')

@section('title', __('admin.providers.details_title'))
@section('page_title', $provider->business_name)

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.details_title') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.providers.business_name') }}</dt>
                        <dd>{{ $provider->business_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.customer_name') }}</dt>
                        <dd>{{ $provider->user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.email') }}</dt>
                        <dd>{{ $provider->user->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.phone') }}</dt>
                        <dd>{{ $provider->user->phone ?? '—' }}</dd>
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

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.providers.documents_title') }}</h2>

                @if ($provider->documents->isEmpty())
                    <p class="text-sm {{ $provider->verification_status === 'pending' ? 'text-amber-600' : 'text-slate-400' }}">
                        {{ __('admin.providers.no_documents') }}
                    </p>
                @else
                    <ul class="text-sm divide-y divide-slate-100">
                        @foreach ($provider->documents as $document)
                            <li class="py-2.5 flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-slate-800">
                                        {{ $document->title ?: __('admin.providers.document_types.' . $document->type) }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ __('admin.providers.document_types.' . $document->type) }}
                                        · {{ $document->original_name }}
                                        · {{ number_format($document->size_bytes / 1024, 0) }} KB
                                        · {{ $document->created_at->toFormattedDateString() }}
                                    </p>
                                </div>
                                <a href="{{ route('admin.providers.documents.download', ['provider' => $provider, 'document' => $document->uuid]) }}"
                                    class="text-xs text-slate-500 underline whitespace-nowrap">{{ __('admin.providers.download') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
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
        </div>
    </div>
@endsection
