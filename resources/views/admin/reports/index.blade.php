@extends('admin.layout')

@section('title', __('admin.reports.title'))
@section('page_title', __('admin.reports.title'))

@section('content')
    {{-- admin.layout has no @stack('scripts') yield point, so Chart.js
    is loaded directly here rather than via @push, which would be
    silently discarded. --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    <form method="GET" class="flex flex-wrap items-end gap-3 mb-8 text-sm bg-white border border-slate-200 rounded-xl p-4">
        <div>
            <label class="block text-xs text-slate-400 mb-1">{{ __('admin.reports.date_range') }}</label>
            <select name="preset" id="preset-select" class="border border-slate-200 rounded-lg px-3 py-2">
                @foreach (['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'this_year', 'custom'] as $option)
                    <option value="{{ $option }}" @selected($preset === $option)>{{ __('admin.reports.presets.' . $option) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div id="custom-range-fields" class="{{ $preset === 'custom' ? '' : 'hidden' }} flex items-end gap-3">
            <div>
                <label class="block text-xs text-slate-400 mb-1">{{ __('admin.reports.from') }}</label>
                <input type="date" name="from" value="{{ request('from', $from->toDateString()) }}"
                    class="border border-slate-200 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">{{ __('admin.reports.to') }}</label>
                <input type="date" name="to" value="{{ request('to', $to->toDateString()) }}"
                    class="border border-slate-200 rounded-lg px-3 py-2">
            </div>
        </div>

        <button type="submit" class="bg-slate-100 rounded-lg px-4 py-2">{{ __('admin.reports.apply') }}</button>

        <p class="text-xs text-slate-400 ms-auto">
            {{ $from->toFormattedDateString() }} &ndash; {{ $to->toFormattedDateString() }}
        </p>
    </form>

    <script>
        document.getElementById('preset-select').addEventListener('change', function () {
            document.getElementById('custom-range-fields').classList.toggle('hidden', this.value !== 'custom');
        });
    </script>

    {{-- Trend charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.reports.revenue_over_time') }}</h2>
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['type' => 'revenue'])) }}"
                    class="text-xs text-slate-500 underline">{{ __('admin.reports.export_csv') }}</a>
            </div>
            <canvas id="revenueChart" height="200"></canvas>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.reports.reservations_over_time') }}</h2>
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['type' => 'reservations'])) }}"
                    class="text-xs text-slate-500 underline">{{ __('admin.reports.export_csv') }}</a>
            </div>
            <canvas id="reservationsChart" height="200"></canvas>
        </div>
    </div>

    {{-- Top performers --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.reports.top_activities') }}</h2>
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['type' => 'activities'])) }}"
                    class="text-xs text-slate-500 underline">{{ __('admin.reports.export_csv') }}</a>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 font-medium">{{ __('admin.reservations.activity') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reports.bookings') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reports.revenue') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topActivities as $row)
                        <tr class="border-b border-slate-50">
                            <td class="py-2">{{ $row->title }}</td>
                            <td class="py-2 text-slate-500">{{ $row->bookings_count }}</td>
                            <td class="py-2 text-slate-500">{{ number_format((float) $row->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 text-center text-slate-400">{{ __('admin.reports.no_data') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.reports.top_providers') }}</h2>
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['type' => 'providers'])) }}"
                    class="text-xs text-slate-500 underline">{{ __('admin.reports.export_csv') }}</a>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 font-medium">{{ __('admin.providers.business_name') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reports.bookings') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reports.revenue') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topProviders as $row)
                        <tr class="border-b border-slate-50">
                            <td class="py-2">{{ $row->business_name }}</td>
                            <td class="py-2 text-slate-500">{{ $row->bookings_count }}</td>
                            <td class="py-2 text-slate-500">{{ number_format((float) $row->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 text-center text-slate-400">{{ __('admin.reports.no_data') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.reports.top_categories') }}</h2>
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['type' => 'categories'])) }}"
                    class="text-xs text-slate-500 underline">{{ __('admin.reports.export_csv') }}</a>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 font-medium">{{ __('admin.nav.categories') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reports.bookings') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reports.revenue') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topCategories as $row)
                        <tr class="border-b border-slate-50">
                            <td class="py-2">{{ $row->name }}</td>
                            <td class="py-2 text-slate-500">{{ $row->bookings_count }}</td>
                            <td class="py-2 text-slate-500">{{ number_format((float) $row->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 text-center text-slate-400">{{ __('admin.reports.no_data') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: @json($revenueOverTime['labels']),
                datasets: [{
                    label: '{{ __('admin.reports.revenue') }}',
                    data: @json($revenueOverTime['data']),
                    borderColor: '#14B8A6',
                    backgroundColor: 'rgba(20, 184, 166, 0.1)',
                    tension: 0.3,
                    fill: true,
                }],
            },
            options: { plugins: { legend: { display: false } } },
        });

        new Chart(document.getElementById('reservationsChart'), {
            type: 'line',
            data: {
                labels: @json($reservationsOverTime['labels']),
                datasets: [{
                    label: '{{ __('admin.reservations.title') }}',
                    data: @json($reservationsOverTime['data']),
                    borderColor: '#0B2E33',
                    backgroundColor: 'rgba(11, 46, 51, 0.1)',
                    tension: 0.3,
                    fill: true,
                }],
            },
            options: { plugins: { legend: { display: false } } },
        });
    </script>
@endsection
