@extends('admin.layout')

@section('title', __('admin.cities.title'))
@section('page_title', __('admin.cities.title'))

@section('content')
    <div class="flex items-start justify-between gap-4 mb-4">
        <p class="text-sm text-slate-500 max-w-2xl">{{ __('admin.cities.intro') }}</p>
        <x-admin.button pill variant="info" href="{{ route('admin.cities.create') }}">{{ __('admin.cities.add') }}</x-admin.button>
    </div>

    @error('city')
        <p class="mb-3 text-sm text-red-600">{{ $message }}</p>
    @enderror

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.cities.order') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.cities.name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.cities.slug') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.cities.activities') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cities as $city)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4 text-slate-500">{{ $city->sort_order }}</td>
                        <td class="py-2.5 px-4">{{ $city->name() }}<p class="text-xs text-slate-400">{{ app()->getLocale() === 'ar' ? $city->name_en : $city->name_ar }}</p></td>
                        <td class="py-2.5 px-4 font-mono text-xs text-slate-500" dir="ltr">{{ $city->slug }}</td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$city->is_active ? 'success' : 'neutral'" :label="$city->is_active ? __('admin.cities.active') : __('admin.cities.inactive')" /></td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $city->activities_count }}</td>
                        <td class="py-2.5 px-4">
                            <div class="flex justify-end gap-2">
                                <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.cities.edit', $city) }}">{{ __('admin.cities.edit') }}</x-admin.button>
                                @unless ($city->activities_count > 0)
                                    <x-admin.confirm-form :action="route('admin.cities.destroy', $city)" method="DELETE" :confirm="__('admin.cities.confirm_delete')" variant="danger" icon="trash">{{ __('admin.cities.delete') }}</x-admin.confirm-form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 px-4 text-center text-slate-400">{{ __('admin.cities.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
