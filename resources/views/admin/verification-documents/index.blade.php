@extends('admin.layout')

@section('title', __('admin.verification_documents.title'))
@section('page_title', __('admin.verification_documents.title'))

@section('content')
    @php $titles = app(\App\Services\VerificationDocumentTypes::class)->titles(); @endphp

    <div class="flex flex-wrap gap-2 mb-4 text-xs">
        @foreach (['pending', 'approved', 'rejected', 'all'] as $tab)
            <a href="{{ route('admin.verification-documents.index', ['status' => $tab]) }}"
                class="px-3 py-1 rounded-full border {{ $status === $tab ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">
                {{ __('admin.verification_documents.statuses.' . $tab) }} ({{ $counts[$tab] ?? 0 }})
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.withdrawals.provider') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.verification_documents.document') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.verification_documents.uploaded') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documents as $document)
                    @php $variant = match ($document->status) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }; @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $document->provider->business_name }}</td>
                        <td class="py-2.5 px-4">{{ $titles[$document->type] ?? $document->type }}<p class="text-xs text-slate-400">{{ $document->original_name }}</p></td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $document->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$variant" :label="__('admin.verification_documents.statuses.' . $document->status)" /></td>
                        <td class="py-2.5 px-4">
                            <div class="flex justify-end">
                                <x-admin.button pill href="{{ route('admin.providers.show', $document->provider) }}#documents">{{ __('admin.verification_documents.review') }}</x-admin.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-slate-400">{{ __('admin.verification_documents.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $documents->links() }}</div>
@endsection
