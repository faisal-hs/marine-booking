{{-- The sex of the person behind an account, for a form: male, female, or not said. $value is the current one. --}}
<div>
    <label for="sex" class="block font-medium mb-1 text-slate-700">{{ __('admin.sex.label') }}</label>
    <select id="sex" name="sex" class="w-full border border-slate-200 rounded-lg px-3 py-2">
        <option value="">—</option>
        @foreach (\App\Enums\Sex::values() as $sex)
            <option value="{{ $sex }}" @selected(old('sex', $value ?? null) === $sex)>{{ __('admin.sex.'.$sex) }}</option>
        @endforeach
    </select>
    @error('sex')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
