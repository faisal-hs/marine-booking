{{-- Shared by create and edit. $broadcast is null when composing a new announcement. --}}
<x-admin.field name="title" :label="__('admin.notifications.broadcast_title_field')" :value="$broadcast?->title" required />
<x-admin.field name="body" type="textarea" rows="5" :label="__('admin.notifications.body_field')" :value="$broadcast?->body" required />

<div>
    <label for="audience" class="block font-medium mb-1 text-slate-700">
        {{ __('admin.notifications.audience') }} <span class="text-red-500">*</span>
    </label>
    <select id="audience" name="audience" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
        @foreach (['customers', 'providers', 'user'] as $audience)
            <option value="{{ $audience }}" @selected(old('audience', $broadcast?->audience) === $audience)>{{ __('admin.notifications.audiences.' . $audience) }}</option>
        @endforeach
    </select>
    @error('audience')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>

<x-admin.field name="recipient" :label="__('admin.notifications.recipient_field')"
    :value="$broadcast?->recipient?->email ?? $broadcast?->recipient?->phone" :help="__('admin.notifications.recipient_help')" />
