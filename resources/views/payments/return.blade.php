{{--
    The page a customer lands on after paying (or not) at Tabby or Tamara. Self-contained: no script and no external stylesheet beyond the brand font, so it works wherever it is
    opened (a phone's browser, an in-app view). Everything comes from PaymentReturnController; the text is lang/{en,ar}/payments.php.
--}}
@php
    $rtl = app()->getLocale() === 'ar';
    $tone = ['paid' => 'ok', 'pending' => 'wait', 'failed' => 'bad', 'cancelled' => 'bad', 'expired' => 'bad'][$status] ?? 'bad';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @if ($refresh)
        <meta http-equiv="refresh" content="8">
    @endif
    <title>{{ __('payments.return.page_title') }} · {{ config('branding.name') }}</title>
    @include('admin.partials.theme-vars')
    <style>
        :root { --ok: #15803d; --ok-bg: #dcfce7; --wait: #b45309; --wait-bg: #fef3c7; --bad: #b91c1c; --bad-bg: #fee2e2; --card: #ffffff; --line: #e2e8f0; --muted: #64748b; }
        @media (prefers-color-scheme: dark) {
            :root { --ok: #4ade80; --ok-bg: #14532d; --wait: #fbbf24; --wait-bg: #451a03; --bad: #f87171; --bad-bg: #450a0a; --card: #1e293b; --line: #334155; --muted: #94a3b8; }
            body { background: #0f172a; color: #f1f5f9; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: var(--brand-page-bg, #f8fafc); color: var(--brand-ink, #0f172a); font-family: var(--brand-font, system-ui), system-ui, -apple-system, 'Segoe UI', Roboto, Tahoma, sans-serif; }
        .card { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 32px 24px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); text-align: center; }
        .badge { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; }
        .badge svg { width: 36px; height: 36px; }
        .ok .badge { background: var(--ok-bg); color: var(--ok); } .wait .badge { background: var(--wait-bg); color: var(--wait); } .bad .badge { background: var(--bad-bg); color: var(--bad); }
        h1 { font-size: 1.35rem; margin: 0 0 8px; } .ok h1 { color: var(--ok); } .wait h1 { color: var(--wait); } .bad h1 { color: var(--bad); }
        p.text { margin: 0 0 20px; color: var(--muted); line-height: 1.6; }
        .reason { margin: 0 0 20px; padding: 10px 14px; border-radius: 12px; background: var(--bad-bg); color: var(--bad); font-size: .92rem; }
        .amount { margin: 4px 0 20px; } .amount small { display: block; color: var(--muted); font-size: .85rem; margin-bottom: 2px; }
        .amount strong { font-size: 2rem; letter-spacing: .01em; } .amount span { font-size: 1rem; color: var(--muted); margin-inline-start: 6px; }
        dl { margin: 0; text-align: start; border-top: 1px solid var(--line); }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); font-size: .93rem; }
        dt { color: var(--muted); flex-shrink: 0; } dd { margin: 0; font-weight: 600; text-align: end; overflow-wrap: anywhere; }
        .ref { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .03em; }
        .button { display: inline-block; margin-top: 24px; padding: 12px 28px; border-radius: 12px; background: var(--brand-primary, #0f766e); color: #fff; text-decoration: none; font-weight: 600; }
        .hint { margin-top: 16px; color: var(--muted); font-size: .85rem; }
    </style>
</head>

<body>
    <main class="card {{ $tone }}" role="status" aria-live="polite">
        <div class="badge" aria-hidden="true">
            @if ($status === 'paid')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
            @elseif ($status === 'pending')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
            @endif
        </div>

        <h1>{{ __("payments.return.{$status}.title") }}</h1>
        <p class="text">{{ __("payments.return.{$status}.text") }}</p>

        @if ($reasonText)
            <p class="reason">{{ $reasonText }}</p>
        @endif

        <div class="amount">
            <small>{{ $status === 'paid' ? __('payments.return.labels.amount_paid') : __('payments.return.labels.amount') }}</small>
            <strong>{{ $amount }}</strong> <span>{{ $currency }}</span>
        </div>

        <dl>
            <div class="row"><dt>{{ __('payments.return.labels.payment_reference') }}</dt><dd class="ref" dir="ltr">{{ $paymentReference }}</dd></div>
            <div class="row"><dt>{{ __('payments.return.labels.booking_reference') }}</dt><dd class="ref" dir="ltr">{{ $bookingReference }}</dd></div>
            @if ($activity)
                <div class="row"><dt>{{ __('payments.return.labels.activity') }}</dt><dd>{{ $activity }}</dd></div>
            @endif
            @if ($when)
                <div class="row"><dt>{{ __('payments.return.labels.when') }}</dt><dd dir="ltr">{{ $when }}</dd></div>
            @endif
            @if ($method)
                <div class="row"><dt>{{ __('payments.return.labels.method') }}</dt><dd>{{ $method }}</dd></div>
            @endif
            @if ($status === 'paid' && $paidAt)
                <div class="row"><dt>{{ __('payments.return.labels.paid_at') }}</dt><dd dir="ltr">{{ $paidAt }}</dd></div>
            @endif
            @if ($refunded)
                <div class="row"><dt>{{ __('payments.return.labels.refunded') }}</dt><dd>{{ $refunded }} {{ $currency }}</dd></div>
            @endif
        </dl>

        @if ($appUrl)
            <a class="button" href="{{ $appUrl }}">{{ __('payments.return.back_to_app') }}</a>
        @endif

        @if ($refresh)
            <p class="hint">{{ __('payments.return.refreshing') }}</p>
        @endif
    </main>
</body>

</html>
