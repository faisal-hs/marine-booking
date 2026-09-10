@extends('public.layout')

@section('title', __('public.about.title') . ' — ' . config('branding.name'))

@section('content')
    <div class="max-w-4xl mx-auto px-6 py-16">
        <h1 class="text-3xl font-semibold mb-6">{{ __('public.about.title') }}</h1>
        <p class="text-slate-600 leading-relaxed mb-4">{{ __('public.about.paragraph_1') }}</p>
        <p class="text-slate-600 leading-relaxed">{{ __('public.about.paragraph_2') }}</p>
    </div>
@endsection
