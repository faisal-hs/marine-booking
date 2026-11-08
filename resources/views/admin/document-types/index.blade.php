@extends('admin.layout')

@section('title', __('admin.document_types.title'))
@section('page_title', __('admin.document_types.title'))

@section('content')
    <div class="flex items-start justify-between gap-4 mb-4">
        <p class="text-sm text-slate-500 max-w-2xl">{{ __('admin.document_types.intro') }}</p>
        <x-admin.button pill variant="info" href="{{ route('admin.document-types.create') }}">{{ __('admin.document_types.add') }}</x-admin.button>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.document_types.order') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.document_types.name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.document_types.key') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.document_types.requirement') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.document_types.documents') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($types as $type)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4 text-slate-500">{{ $type->sort_order }}</td>
                        <td class="py-2.5 px-4">{{ $type->title() }}<p class="text-xs text-slate-400">{{ $type->title_ar === $type->title() ? $type->title_en : $type->title_ar }}</p></td>
                        <td class="py-2.5 px-4 font-mono text-xs text-slate-500" dir="ltr">{{ $type->key }}</td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$type->is_mandatory ? 'warning' : 'neutral'" :label="$type->is_mandatory ? __('admin.document_types.mandatory') : __('admin.document_types.optional')" /></td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$type->is_active ? 'success' : 'neutral'" :label="$type->is_active ? __('admin.document_types.active') : __('admin.document_types.inactive')" /></td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $inUse[$type->key] ?? 0 }}</td>
                        <td class="py-2.5 px-4">
                            <div class="flex justify-end gap-2">
                                <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.document-types.edit', $type) }}">{{ __('admin.document_types.edit') }}</x-admin.button>
                                @unless (($inUse[$type->key] ?? 0) > 0)
                                    <x-admin.confirm-form :action="route('admin.document-types.destroy', $type)" method="DELETE" :confirm="__('admin.document_types.confirm_delete')" variant="danger" icon="trash">{{ __('admin.document_types.delete') }}</x-admin.confirm-form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-500">{{ __('admin.document_types.mandatory_note') }}</p>
@endsection
