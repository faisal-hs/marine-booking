@extends('admin.layout')

@section('title', __('admin.categories.title'))
@section('page_title', __('admin.categories.title'))

@section('content')
    <div class="flex justify-between items-center mb-4">
        <div></div>
        @can('categories.create')
            <a href="{{ route('admin.categories.create') }}"
                class="text-xs bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.categories.add') }}</a>
        @endcan
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.categories.image') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.categories.name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.categories.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.categories.sort_order') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.categories.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $category)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">
                            @if ($category->image)
                                <img src="{{ Storage::disk('public')->url($category->image) }}"
                                    class="w-10 h-10 rounded-lg object-cover">
                            @else
                                <div class="w-10 h-10 rounded-lg bg-slate-100"></div>
                            @endif
                        </td>
                        <td class="py-2.5 px-4">{{ $category->name }}</td>
                        <td class="py-2.5 px-4">
                            <span @class([
                                'inline-flex px-3 py-1 rounded-full text-xs border',
                                'bg-green-50 text-green-700 border-green-200' => $category->status === 'active',
                                'bg-slate-100 text-slate-600 border-slate-200' => $category->status === 'inactive',
                            ])>{{ __('admin.categories.' . $category->status) }}</span>
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $category->sort_order }}</td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2">
                                @can('categories.update')
                                    <a href="{{ route('admin.categories.edit', $category) }}"
                                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition">{{ __('admin.categories.edit') }}</a>
                                @endcan
                                @can('categories.delete')
                                    <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                                        onsubmit="return confirm('{{ __('admin.categories.confirm_delete') }}')">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                            class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-red-50 text-red-700 hover:bg-red-100 transition">{{ __('admin.categories.delete') }}</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $categories->links() }}</div>
@endsection
