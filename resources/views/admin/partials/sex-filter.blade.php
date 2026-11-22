{{-- A list's filter by sex: any, male or female. $value is the one chosen. --}}
<select name="sex" class="border border-slate-200 rounded-lg px-3 py-1.5">
    <option value="">{{ __('admin.sex.any') }}</option>
    @foreach (\App\Enums\Sex::values() as $sex)
        <option value="{{ $sex }}" @selected(($value ?? '') === $sex)>{{ __('admin.sex.'.$sex) }}</option>
    @endforeach
</select>
