#!/usr/bin/env python3
"""
Checks, against a RUNNING server and its real database, that bookings by time hold up when many customers book at the same instant.

Why a script and not a PHPUnit test: the tests run on SQLite, which has no row locks, so it cannot race. Run this on the database you will use (MySQL / MariaDB / PostgreSQL), with the
server running MANY workers (for example `PHP_CLI_SERVER_WORKERS=14 php -S 127.0.0.1:8000 -t public public/index.php`, or php-fpm), against a DEMO-SEEDED database where the
default OTP code is on (OTP_DEFAULT_CODE_ENABLED=true): it signs in the demo provider and registers a few customers. It makes its own activities (named "Concurrency ...").

    BASE_URL=http://127.0.0.1:8000/api/v1 OTP_CODE=123456 python3 tests/Concurrency/check_booking_concurrency.py [--customers 14] [--skip-persons]

What it checks (every check uses only the API):
  A  N customers book the SAME window at once              -> exactly one booking, the rest are 409 (never a 500)
  B  N customers book overlapping windows at once          -> no two live bookings overlap, and none is a 500
  C  N customers book windows that only touch              -> all of them are made (no false conflicts), no 500
  D  one customer double-taps (one Idempotency-Key, 6 at once) -> one booking, and the others get that booking back
  E  a booking is cancelled                                -> the same time can be booked again straight away
  P1 bookings by persons, capacity 3, N customers of one guest at once  -> exactly 3 are made, the rest are 409, no 500
  P2 capacity 3, N customers of TWO guests at once                      -> exactly 1 is made (never 2: that would be 4 places)
  P3 capacity 5, N customers of 1 to 3 guests at once                   -> the guests made never exceed 5, and the places left are what is left
  P4 a booking is cancelled                                             -> exactly one more booking of its size can be made
  P5 bookings by units (stock 2), N customers of one unit at once       -> exactly 2 are made

It exits 0 when A-E and P1-P5 all pass, 1 otherwise (--skip-persons leaves P1-P5 out). Unpaid bookings in these checks hold their time until their hold runs out (`simulate: async`), so run it on a database you can discard.
"""
import argparse, collections, json, os, sys, threading, time, urllib.error, urllib.request, uuid
from datetime import datetime, timedelta

BASE = os.environ.get('BASE_URL', 'http://127.0.0.1:8000/api/v1').rstrip('/')
CODE = os.environ.get('OTP_CODE', '123456')
PROVIDER_PHONE = os.environ.get('PROVIDER_PHONE', '0511111111')


def call(method, path, body=None, token=None, headers=None):
    h = {'Accept': 'application/json'}
    if token:
        h['Authorization'] = 'Bearer ' + token
    data = None
    if body is not None:
        h['Content-Type'] = 'application/json'
        data = json.dumps(body).encode()
    h.update(headers or {})
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE + path, data=data, headers=h, method=method), timeout=60) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.load(e)
        except Exception:
            return e.code, {}


def login(kind, phone, **extra):
    call('POST', f'/auth/{kind}/otp/request', dict({'phone': phone}, **extra))
    status, r = call('POST', f'/auth/{kind}/otp/verify', {'phone': phone, 'code': CODE})
    if status != 200:
        sys.exit(f'Could not sign in {kind} {phone}: HTTP {status} {r.get("message")}. Is OTP_DEFAULT_CODE_ENABLED on, and OTP_CODE right?')
    return r['data']['token']


def storm(requests):
    """Send every request at the same instant. requests: [(token, body)]. Returns [(status, json)]."""
    barrier, out = threading.Barrier(len(requests)), [None] * len(requests)

    def go(i):
        token, body = requests[i]
        barrier.wait()
        out[i] = call('POST', '/customer-app/bookings', body, token, {'Idempotency-Key': 'c-' + uuid.uuid4().hex[:16]})

    threads = [threading.Thread(target=go, args=(i,)) for i in range(len(requests))]
    [t.start() for t in threads]
    [t.join() for t in threads]
    return out


def tally(results):
    return dict(collections.Counter(s for s, _ in results))


def taken(activity_id, slot_id, month, day):
    """The windows the API says are taken on a day (from the availability), as (from, to) datetimes."""
    _, r = call('GET', f'/customer-app/activities/{activity_id}/availability?month={month}')
    for d in r['data']['days']:
        if d['date'] == day:
            for s in d['slots']:
                if s['id'] == slot_id:
                    f = '%Y-%m-%d %H:%M'
                    return [(datetime.strptime(w['from'], f), datetime.strptime(w['to'], f)) for w in s.get('booked_windows', [])]
    return []


def overlapping_pairs(windows):
    return sum(1 for i in range(len(windows)) for j in range(i + 1, len(windows)) if windows[i][0] < windows[j][1] and windows[j][0] < windows[i][1])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--customers', type=int, default=14)
    ap.add_argument('--skip-persons', action='store_true')
    n = ap.parse_args().customers
    persons = not ap.parse_args().skip_persons

    # An operating period that crosses midnight, on the Friday after today (so the windows cross midnight too).
    today = datetime.now()
    friday = today + timedelta(days=(4 - today.weekday()) % 7 or 7)
    day, nxt = friday.strftime('%Y-%m-%d'), (friday + timedelta(days=1)).strftime('%Y-%m-%d')
    month = friday.strftime('%Y-%m')
    provider = login('provider', PROVIDER_PHONE)
    _, cats = call('GET', '/provider/categories', token=provider)
    _, cities = call('GET', '/public/cities')
    cat, city = cats['data'][0]['id'], next(c['id'] for c in cities if c['is_active'])
    run = int(time.time()) % 900 + 100
    customers = [login('customer', f'0553{run}{i:03d}', name=f'Concurrency {i}') for i in range(n + 5)]
    night = [{'day_of_week': 'friday', 'start_time': '20:00', 'end_time': '05:00'}]
    option = {'name': 'By time', 'booking_model': 'per_hour', 'is_default': True, 'min_duration_minutes': 30, 'max_duration_minutes': 240, 'pricing_rules': [{'price': 200}]}

    def make(title, schedule, opt):
        status, r = call('POST', '/provider/activities', {'category_id': cat, 'city_id': city, 'title': f'{title} {run}', 'max_participants': 3, 'schedule': schedule, 'booking_options': [opt]}, provider)
        if status != 201:
            sys.exit(f'Could not make the activity: HTTP {status} {json.dumps(r.get("errors") or r.get("message"))}')
        _, a = call('GET', f'/customer-app/activities/{r["data"]["id"]}/availability?month={month}')
        return r['data']['id'], next(d for d in a['data']['days'] if d['date'] == day)['slots'][0]['id']

    def body(a, slot, f, t):
        return {'activity_id': a, 'slot_id': slot, 'from': f, 'to': t, 'contact_name': 'Concurrency Tester', 'contact_phone': '0551234567', 'payment_method': 'card', 'simulate': 'async'}

    def at(h, m, plus=0):
        base = friday if h >= 20 else friday + timedelta(days=1)
        return (base.replace(hour=h, minute=m) + timedelta(minutes=plus)).strftime('%Y-%m-%d %H:%M')

    results = {}
    a, slot = make('Concurrency A', night, option)
    res = storm([(customers[i], body(a, slot, at(21, 0), at(21, 0, 60))) for i in range(n)])
    w = taken(a, slot, month, day)
    results['A'] = tally(res).get(201, 0) == 1 and len(w) == 1 and set(tally(res)) <= {201, 409}
    print(f'A  {n} identical windows at once        : {tally(res)} | taken windows: {len(w)}', 'PASS' if results['A'] else 'FAIL')

    a, slot = make('Concurrency B', night, option)
    # Windows of 60 minutes that start every 30 minutes from 20:00: each overlaps its neighbours, so only some can win.
    def window(i, length):
        start = friday.replace(hour=20, minute=0) + timedelta(minutes=30 * i)
        return start.strftime('%Y-%m-%d %H:%M'), (start + timedelta(minutes=length)).strftime('%Y-%m-%d %H:%M')

    res = storm([(customers[i], body(a, slot, *window(i % 16, 60))) for i in range(n)])   # (16 starts fit in the period; more customers share them)
    w = taken(a, slot, month, day)
    results['B'] = overlapping_pairs(w) == 0 and len(w) >= 1 and set(tally(res)) <= {201, 409}
    print(f'B  {n} overlapping windows at once      : {tally(res)} | taken windows: {len(w)}, overlapping pairs: {overlapping_pairs(w)}', 'PASS' if results['B'] else 'FAIL')

    a, slot = make('Concurrency C', night, option)
    k = min(n, 8)
    res = storm([(customers[i], body(a, slot, *window(i, 30))) for i in range(k)])
    results['C'] = tally(res).get(201, 0) == k and overlapping_pairs(taken(a, slot, month, day)) == 0
    print(f'C  {k} back-to-back windows at once       : {tally(res)}', 'PASS' if results['C'] else 'FAIL')

    a, slot = make('Concurrency D', night, option)
    key, barrier, out = 'same-' + uuid.uuid4().hex[:10], threading.Barrier(6), [None] * 6

    def tap(i):
        barrier.wait()
        out[i] = call('POST', '/customer-app/bookings', body(a, slot, at(21, 0), at(21, 0, 30)), customers[n], {'Idempotency-Key': key})

    threads = [threading.Thread(target=tap, args=(i,)) for i in range(6)]
    [t.start() for t in threads]
    [t.join() for t in threads]
    ids = {r['data']['booking_id'] for s, r in out if s in (200, 201)}
    results['D'] = len(ids) == 1 and all(s in (200, 201) for s, _ in out) and len(taken(a, slot, month, day)) == 1
    print(f'D  one customer double-taps (6 at once)  : {tally(out)} | distinct bookings: {len(ids)}', 'PASS' if results['D'] else 'FAIL')

    a, slot = make('Concurrency E', night, option)
    s1, r1 = call('POST', '/customer-app/bookings', body(a, slot, at(22, 0), at(22, 0, 60)), customers[n + 1], {'Idempotency-Key': 'e1-' + uuid.uuid4().hex[:8]})
    s2, _ = call('POST', '/customer-app/bookings', body(a, slot, at(22, 0), at(22, 0, 60)), customers[n + 2], {'Idempotency-Key': 'e2-' + uuid.uuid4().hex[:8]})
    sc, _ = call('POST', f'/customer-app/bookings/{r1["data"]["booking_id"]}/cancel', {'reason': 'check'}, customers[n + 1])
    s3, _ = call('POST', '/customer-app/bookings', body(a, slot, at(22, 0), at(22, 0, 60)), customers[n + 2], {'Idempotency-Key': 'e3-' + uuid.uuid4().hex[:8]})
    results['E'] = (s1, s2, sc, s3) == (201, 409, 200, 201)
    print(f'E  held, refused, cancelled, booked again : {(s1, s2, sc, s3)}', 'PASS' if results['E'] else 'FAIL')

    if persons:
        sat = (friday + timedelta(days=1)).strftime('%Y-%m-%d')

        def make_persons(title, option_extra, activity_extra=None):
            opt = dict({'name': 'Standard', 'booking_model': 'per_person', 'is_default': True, 'pricing_rules': [{'participant_type': 'adult', 'price': 100}]}, **option_extra)
            status, r = call('POST', '/provider/activities', dict({'category_id': cat, 'city_id': city, 'title': f'{title} {run}', 'max_participants': 20, 'schedule': [{'day_of_week': 'saturday', 'start_time': '09:00', 'end_time': '13:00'}], 'booking_options': [opt]}, **(activity_extra or {})), provider)
            if status != 201:
                sys.exit(f'Could not make the activity: HTTP {status} {json.dumps(r.get("errors") or r.get("message"))}')
            _, av = call('GET', f'/customer-app/activities/{r["data"]["id"]}/availability?month={month}')
            return r['data']['id'], next(d for d in av['data']['days'] if d['date'] == sat)['slots'][0]['id']

        def spots_left(activity_id, slot_id):
            _, av = call('GET', f'/customer-app/activities/{activity_id}/availability?month={month}')
            return next(s for d in av['data']['days'] if d['date'] == sat for s in d['slots'] if s['id'] == slot_id)['spots_left']

        def persons_body(a, slot, guests=None, quantity=None):
            b = {'activity_id': a, 'slot_id': slot, 'date': sat, 'contact_name': 'Concurrency Tester', 'contact_phone': '0551234567', 'payment_method': 'card', 'simulate': 'async'}
            if guests is not None:
                b['guests'] = guests
            if quantity is not None:
                b['quantity'] = quantity
            return b

        a, slot = make_persons('Concurrency P1', {'max_participants': 3})
        res = storm([(customers[i], persons_body(a, slot, 1)) for i in range(n)])
        results['P1'] = tally(res).get(201, 0) == 3 and spots_left(a, slot) == 0 and set(tally(res)) <= {201, 409}
        print(f'P1 {n} customers of 1 guest, capacity 3   : {tally(res)} | places left: {spots_left(a, slot)}', 'PASS' if results['P1'] else 'FAIL')

        a, slot = make_persons('Concurrency P2', {'max_participants': 3})
        res = storm([(customers[i], persons_body(a, slot, 2)) for i in range(n)])
        results['P2'] = tally(res).get(201, 0) == 1 and spots_left(a, slot) == 1 and set(tally(res)) <= {201, 409}
        print(f'P2 {n} customers of 2 guests, capacity 3  : {tally(res)} | places left: {spots_left(a, slot)}', 'PASS' if results['P2'] else 'FAIL')

        a, slot = make_persons('Concurrency P3', {'max_participants': 5})
        sizes = [1 + (i % 3) for i in range(n)]
        res = storm([(customers[i], persons_body(a, slot, sizes[i])) for i in range(n)])
        made = sum(sizes[i] for i, (s_, _) in enumerate(res) if s_ == 201)
        results['P3'] = made <= 5 and spots_left(a, slot) == 5 - made and set(tally(res)) <= {201, 409}
        print(f'P3 {n} customers of 1-3 guests, capacity 5: {tally(res)} | guests made: {made}, places left: {spots_left(a, slot)}', 'PASS' if results['P3'] else 'FAIL')

        a, slot = make_persons('Concurrency P4', {'max_participants': 2})
        s1, r1 = call('POST', '/customer-app/bookings', persons_body(a, slot, 2), customers[n + 3], {'Idempotency-Key': 'p4a-' + uuid.uuid4().hex[:8]})
        s2, _ = call('POST', '/customer-app/bookings', persons_body(a, slot, 2), customers[n + 4], {'Idempotency-Key': 'p4b-' + uuid.uuid4().hex[:8]})
        sc, _ = call('POST', f'/customer-app/bookings/{r1["data"]["booking_id"]}/cancel', {'reason': 'check'}, customers[n + 3])
        res = storm([(customers[i], persons_body(a, slot, 2)) for i in range(min(n, 6))])
        results['P4'] = (s1, s2, sc) == (201, 409, 200) and tally(res).get(201, 0) == 1 and set(tally(res)) <= {201, 409}
        print(f'P4 full, cancelled, then 6 at once        : {(s1, s2, sc)} then {tally(res)}', 'PASS' if results['P4'] else 'FAIL')

        a, slot = make_persons('Concurrency P5', {'booking_model': 'per_unit', 'max_quantity': 2, 'min_quantity': 1, 'pricing_rules': [{'price': 500}]})
        res = storm([(customers[i], persons_body(a, slot, None, 1)) for i in range(n)])
        results['P5'] = tally(res).get(201, 0) == 2 and set(tally(res)) <= {201, 409}
        print(f'P5 {n} customers of 1 unit, stock 2       : {tally(res)}', 'PASS' if results['P5'] else 'FAIL')

    ok = all(results.values())
    print('\nALL PASS' if ok else '\nSOME CHECKS FAILED: ' + ', '.join(k for k, v in results.items() if not v))
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
