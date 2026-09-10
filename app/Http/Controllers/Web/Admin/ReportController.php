<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Report\ReportFilterRequest;
use App\Services\ReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected ReportService $service) {}

    public function index(ReportFilterRequest $request): View
    {
        Gate::authorize('reports.view');

        [$from, $to, $preset] = $request->dateRange();

        return view('admin.reports.index', [
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
            'revenueOverTime' => $this->service->revenueOverTime($from, $to),
            'reservationsOverTime' => $this->service->reservationsOverTime($from, $to),
            'topActivities' => $this->service->topActivities($from, $to),
            'topProviders' => $this->service->topProviders($from, $to),
            'topCategories' => $this->service->topCategories($from, $to),
        ]);
    }

    public function export(ReportFilterRequest $request, string $type): StreamedResponse
    {
        Gate::authorize('reports.view');

        [$from, $to] = $request->dateRange();

        [$headers, $rows] = match ($type) {
            'revenue' => $this->revenueRows($from, $to),
            'reservations' => $this->reservationsRows($from, $to),
            'activities' => $this->activitiesRows($from, $to),
            'providers' => $this->providersRows($from, $to),
            'categories' => $this->categoriesRows($from, $to),
            default => abort(422, 'Unknown report type.'),
        };

        return $this->streamCsv("{$type}-report.csv", $headers, $rows);
    }

    protected function revenueRows($from, $to): array
    {
        $series = $this->service->revenueOverTime($from, $to);
        $rows = [];
        foreach ($series['labels'] as $i => $label) {
            $rows[] = [$label, $series['data'][$i]];
        }

        return [['Date', 'Gross Revenue (EGP)'], $rows];
    }

    protected function reservationsRows($from, $to): array
    {
        $series = $this->service->reservationsOverTime($from, $to);
        $rows = [];
        foreach ($series['labels'] as $i => $label) {
            $rows[] = [$label, $series['data'][$i]];
        }

        return [['Date', 'Reservations'], $rows];
    }

    protected function activitiesRows($from, $to): array
    {
        $rows = $this->service->topActivities($from, $to, 100)
            ->map(fn($row) => [$row->title, $row->bookings_count, $row->revenue])
            ->all();

        return [['Activity', 'Bookings', 'Revenue (EGP)'], $rows];
    }

    protected function providersRows($from, $to): array
    {
        $rows = $this->service->topProviders($from, $to, 100)
            ->map(fn($row) => [$row->business_name, $row->bookings_count, $row->revenue])
            ->all();

        return [['Provider', 'Bookings', 'Revenue (EGP)'], $rows];
    }

    protected function categoriesRows($from, $to): array
    {
        $rows = $this->service->topCategories($from, $to, 100)
            ->map(fn($row) => [$row->name, $row->bookings_count, $row->revenue])
            ->all();

        return [['Category', 'Bookings', 'Revenue (EGP)'], $rows];
    }

    protected function streamCsv(string $filename, array $headerRow, array $rows): StreamedResponse
    {
        $callback = function () use ($headerRow, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headerRow);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
