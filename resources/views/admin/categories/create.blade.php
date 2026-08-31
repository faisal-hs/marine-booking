@extends('admin.layout')

@section('title', __('admin.categories.add_title'))
@section('page_title', __('admin.categories.add_title'))

@section('content')
    <div class="max-w-lg">
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.categories.store') }}" enctype="multipart/form-data"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.categories.name') }}</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.categories.description') }}</label>
                <textarea name="description" rows="3"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">{{ old('description') }}</textarea>
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.categories.image') }}</label>
                <input type="file" name="image" accept="image/*" class="w-full text-sm">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.categories.status') }}</label>
                <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2">
                    <option value="active">{{ __('admin.categories.active') }}</option>
                    <option value="inactive">{{ __('admin.categories.inactive') }}</option>
                </select>
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.categories.sort_order') }}</label>
                <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.categories.create_submit') }}</button>
        </form>
    </div>
@endsection
