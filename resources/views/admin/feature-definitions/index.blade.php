@extends('admin.layout')

@section('title', __('admin.feature_definitions.title'))
@section('page_title', __('admin.feature_definitions.title'))

@section('content')
<div class="flex justify-between items-center mb-4">
    <div></div>
    @can('feature_definitions.create')
        <a href="{{ route('admin.feature-definitions.create') }}" class="text-xs bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.feature_definitions.add') }}</a>
    @endcan
</div>

<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                <th class="py-2.5 px-4 font-medium">{{ __('admin.feature_definitions.category') }}</th>
                <th class="py-2.5 px-4 font-medium">{{ __('admin.feature_definitions.label') }}</th>
                <th class="py-2.5 px-4 font-medium">{{ __('admin.feature_definitions.key') }}</th>
                <th class="py-2.5 px-4 font-medium">{{ __('admin.feature_definitions.type') }}</th>
                <th class="py-2.5 px-4 font-medium">{{ __('admin.feature_definitions.status') }}</th>
                <th class="py-2.5 px-4 font-medium">{{ __('admin.feature_definitions.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($definitions as $definition)
                <tr class="border-b border-slate-50">
                    <td class="py-2.5 px-4">{{ $definition->category->name }}</td>
                    <td class="py-2.5 px-4">{{ $definition->label }}</td>
                    <td class="py-2.5 px-4 text-slate-500 font-mono text-xs">{{ $definition->key }}</td>
                    <td class="py-2.5 px-4 text-slate-500">{{ __('admin.feature_definitions.types.'.$definition->type) }}</td>
                    <td class="py-2.5 px-4">
                        <span @class([
                            'inline-flex px-3 py-1 rounded-full text-xs border',
                            'bg-green-50 text-green-700 border-green-200' => $definition->status === 'active',
                            'bg-slate-100 text-slate-600 border-slate-200' => $definition->status === 'inactive',
                        ])>{{ __('admin.feature_definitions.'.$definition->status) }}</span>
                    </td>
                    <td class="py-2.5 px-4">
                        <div class="flex items-center gap-2">
                            @can('feature_definitions.update')
                                <a href="{{ route('admin.feature-definitions.edit', $definition) }}"
                                   class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition">{{ __('admin.feature_definitions.edit') }}</a>
                            @endcan
                            @can('feature_definitions.delete')
                                <form method="POST" action="{{ route('admin.feature-definitions.destroy', $definition) }}"
                                      onsubmit="return confirm('{{ __('admin.feature_definitions.confirm_delete') }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-red-50 text-red-700 hover:bg-red-100 transition">{{ __('admin.feature_definitions.delete') }}</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $definitions->links() }}</div>
@endsection
