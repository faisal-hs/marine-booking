<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\ReportRequest;
use App\Http\Requests\ProviderApp\StatementRequest;
use App\Models\Provider;
use App\Services\ProviderApp\ProviderEarnings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EarningsController extends ProviderAppController
{
    public function __construct(private readonly ProviderEarnings $earnings) {}

    public function summary(Request $request)
    {
        return $this->ok($this->earnings->summary($this->provider($request)));
    }

    public function report(ReportRequest $request)
    {
        return $this->ok($this->earnings->report($this->provider($request), $request->integer('year')));
    }

    /** A link to the month's statement that works for a few minutes and only for this provider. */
    public function statement(StatementRequest $request)
    {
        $month = $request->validated('month');
        $url = URL::temporarySignedRoute('provider.earnings.statement.download', now()->addMinutes((int) config('wallet.statement_link_minutes')), ['provider' => $this->provider($request)->uuid, 'month' => $month]);

        return $this->ok(['url' => $url, 'month' => $month, 'currency' => config('platform.currency')]);
    }

    /** The statement itself (CSV). Reached through the signed link, so no token is needed; the signature is what proves it is this provider's. */
    public function download(StatementRequest $request): StreamedResponse
    {
        $provider = Provider::where('uuid', $request->query('provider'))->firstOrFail();
        $month = $request->validated('month');
        $rows = $this->earnings->statementRows($provider, $month);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "statement-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
