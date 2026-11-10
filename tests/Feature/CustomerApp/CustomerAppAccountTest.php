<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Favorite;
use App\Models\Payment;
use App\Models\ProviderRequest;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use App\Services\CustomerApp\AccountDeletion;
use App\Services\NotificationFeed;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** The customer's notifications, profile, signing out and deleting the account. Everything here is private to the customer. */
class CustomerAppAccountTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private User $user;

    private Customer $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        Storage::fake('public');
        $this->a = $this->approvedProvider();
        [$this->user, $this->customer, $this->token] = $this->signedInCustomer(['name' => 'Ahmed Al-Harbi', 'email' => 'ahmed@gmail.com', 'phone' => '+966501234567', 'email_verified_at' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function notify(User $user, string $type, string $message = 'x', array $data = [], bool $read = false, ?string $class = null)
    {
        return $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => $class ?? 'App\Notifications\Reservations\ReservationConfirmedNotification', 'data' => ['type' => $type, 'message' => $message] + $data, 'read_at' => $read ? now() : null]);
    }

    private function png(string $name = 'me.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    // --- Notifications ----------------------------------------------------------------------------------------------

    public function test_a_notification_has_the_specifications_fields_with_a_title_and_the_booking_it_is_about(): void
    {
        $n = $this->notify($this->user, 'reservation.confirmed', 'Your Red Sea Scuba Diving booking is confirmed.', ['reservation_id' => 55]);

        $r = $this->ca($this->token, 'GET', '/notifications')->assertOk();

        $row = $r->json('data.0');
        $this->assertSame(['id', 'type', 'title', 'body', 'is_read', 'read_at', 'created_at', 'booking_id'], array_keys($row));
        $this->assertSame([$n->id, 'booking', 'Booking confirmed', 'Your Red Sea Scuba Diving booking is confirmed.', false, null, '2026-11-15T12:00:00Z', 55],
            [$row['id'], $row['type'], $row['title'], $row['body'], $row['is_read'], $row['read_at'], $row['created_at'], $row['booking_id']]);
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($r->json('meta')));
    }

    public function test_the_type_groups_the_titles_and_follows_the_language(): void
    {
        $this->notify($this->user, 'payment.refunded', 'Refunded');
        $this->notify($this->user, 'something.new', 'Odd');

        $en = collect($this->ca($this->token, 'GET', '/notifications')->json('data'))->keyBy('body');
        $ar = collect($this->ca($this->token, 'GET', '/notifications', [], ['Accept-Language' => 'ar'])->json('data'))->keyBy('body');

        $this->assertSame(['payment', 'Refund issued'], [$en['Refunded']['type'], $en['Refunded']['title']]);
        $this->assertSame(['general', 'Notification'], [$en['Odd']['type'], $en['Odd']['title']], 'An unknown kind still gets a title');
        $this->assertSame(['payment', 'تم رد المبلغ', 'إشعار'], [$ar['Refunded']['type'], $ar['Refunded']['title'], $ar['Odd']['title']]);
    }

    public function test_only_the_customers_own_feed_newest_first_and_paged(): void
    {
        [$other] = $this->makeCustomer();
        $this->notify($other, 'reservation.confirmed', 'Not mine');
        $this->notify($this->user, 'provider.booking.confirmed', 'A provider matter', [], false, NotificationFeed::PROVIDER_ONLY[0]);
        $first = $this->notify($this->user, 'reservation.created', 'First');
        $this->travel(1)->minutes();
        $second = $this->notify($this->user, 'reservation.confirmed', 'Second');
        foreach (range(1, 22) as $i) {
            $this->notify($this->user, 'reservation.confirmed', "Bulk {$i}");
        }

        $r = $this->ca($this->token, 'GET', '/notifications')->assertOk();
        $bodies = $this->ca($this->token, 'GET', '/notifications', ['per_page' => 50])->json('data.*.body');

        $this->assertSame(24, $r->json('meta.total'), 'Not the other customer\'s, not the provider-account one');
        $this->assertCount(20, $r->json('data'));
        $this->assertNotContains('Not mine', $bodies);
        $this->assertNotContains('A provider matter', $bodies);
        $this->assertContains('First', $bodies);
        $this->assertNotNull($second);
        $this->assertNotNull($first);
    }

    public function test_marking_one_as_read_is_safe_to_repeat_and_only_for_the_owner(): void
    {
        $mine = $this->notify($this->user, 'reservation.confirmed');
        [$other, , $otherToken] = $this->signedInCustomer();
        $theirs = $this->notify($other, 'reservation.confirmed');

        $r = $this->ca($this->token, 'POST', "/notifications/{$mine->id}/read")->assertOk();
        $this->assertSame(['Marked as read', ['id' => $mine->id, 'is_read' => true]], [$r->json('message'), $r->json('data')]);
        $first = $mine->refresh()->read_at;
        $this->travel(5)->minutes();
        $this->ca($this->token, 'POST', "/notifications/{$mine->id}/read")->assertOk();
        $this->assertEquals($first, $mine->refresh()->read_at, 'Reading it again changes nothing');

        $this->ca($this->token, 'POST', "/notifications/{$theirs->id}/read")->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->ca($this->token, 'POST', '/notifications/not-a-real-id/read')->assertNotFound();
        $this->assertNull($theirs->refresh()->read_at);
        $this->assertNotNull($otherToken);
        $this->assertNotNull($other);
    }

    public function test_marking_all_as_read_touches_only_this_customers_own_feed(): void
    {
        [$other] = $this->makeCustomer();
        $this->notify($this->user, 'reservation.confirmed');
        $this->notify($this->user, 'reservation.created');
        $providerOnly = $this->notify($this->user, 'provider.booking.confirmed', 'x', [], false, NotificationFeed::PROVIDER_ONLY[0]);
        $theirs = $this->notify($other, 'reservation.confirmed');

        $r = $this->ca($this->token, 'POST', '/notifications/read-all')->assertOk();

        $this->assertSame(['All marked as read', 2], [$r->json('message'), $r->json('data.marked')]);
        $this->assertNull($providerOnly->refresh()->read_at, 'The provider account\'s feed is not the customer\'s');
        $this->assertNull($theirs->refresh()->read_at);
        $this->assertSame(0, $this->ca($this->token, 'GET', '/home')->json('data.unread_notifications'));
        $this->ca($this->token, 'POST', '/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 0);
    }

    // --- Profile ----------------------------------------------------------------------------------------------------

    public function test_the_profile_has_the_specifications_fields(): void
    {
        $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        $this->makeBooking($this->a, $this->customer, '2026-11-02', 'completed');
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        Favorite::create(['customer_id' => $this->customer->id, 'activity_id' => $this->a['activity']->id]);
        $this->makeReview($this->a['activity'], $this->customer, 5);

        $d = $this->ca($this->token, 'GET', '/profile')->assertOk()->json('data');

        $this->assertSame(['id', 'name', 'email', 'phone', 'avatar', 'is_verified', 'phone_verified', 'email_verified', 'is_provider', 'provider_status', 'stats', 'notifications'], array_keys($d));
        $this->assertSame([$this->customer->id, 'Ahmed Al-Harbi', 'ahmed@gmail.com', '+966501234567', null, true, true, false, false, 'none'], [$d['id'], $d['name'], $d['email'], $d['phone'], $d['avatar'], $d['is_verified'], $d['phone_verified'], $d['email_verified'], $d['is_provider'], $d['provider_status']]);
        $this->assertSame(['trips' => 2, 'saved' => 1, 'reviews' => 1], $d['stats'], 'Trips are the completed ones');
        $this->assertSame(['email' => true, 'sms' => false, 'marketing' => true], $d['notifications']);
    }

    public function test_the_stats_are_the_customers_own(): void
    {
        [, $other] = $this->makeCustomer();
        $this->makeBooking($this->a, $other, '2026-11-01', 'completed');
        Favorite::create(['customer_id' => $other->id, 'activity_id' => $this->a['activity']->id]);

        $this->assertSame(['trips' => 0, 'saved' => 0, 'reviews' => 0], $this->ca($this->token, 'GET', '/profile')->json('data.stats'));
    }

    public function test_where_the_person_stands_as_a_provider_is_shown(): void
    {
        $this->assertSame(['none', false], $this->standing());

        [, $applied] = $this->makeProvider('pending');
        $request = ProviderRequest::create(['user_id' => $this->user->id, 'provider_id' => $applied->id, 'source' => 'customer_upgrade', 'status' => 'pending', 'submitted_at' => now()]);
        $this->assertSame(['pending', false], $this->standing());

        $request->update(['status' => 'rejected']);
        $this->assertSame(['rejected', false], $this->standing());
    }

    public function test_a_customer_who_is_also_an_approved_provider_is_shown_as_one(): void
    {
        $this->a['provider']->update(['user_id' => $this->user->id]);

        $this->assertSame(['approved', true], $this->standing());

        $this->a['provider']->update(['verification_status' => 'pending']);
        $this->assertSame(['pending', false], $this->standing());
    }

    private function standing(): array
    {
        $d = $this->ca($this->token, 'GET', '/profile')->json('data');

        return [$d['provider_status'], $d['is_provider']];
    }

    public function test_a_phone_that_is_not_verified_is_reported_as_it_is(): void
    {
        $this->user->forceFill(['phone_verified_at' => null, 'email_verified_at' => now()])->save();

        $d = $this->ca($this->token, 'GET', '/profile')->json('data');

        $this->assertSame([false, false, true], [$d['is_verified'], $d['phone_verified'], $d['email_verified']]);
    }

    public function test_only_what_is_sent_is_changed(): void
    {
        $r = $this->ca($this->token, 'POST', '/profile/update', ['name' => 'Ahmed Salem'])->assertOk();

        $this->assertSame(['Profile updated', 'Ahmed Salem', 'ahmed@gmail.com'], [$r->json('message'), $r->json('data.name'), $r->json('data.email')]);
        $this->assertSame(['Ahmed Salem', 'ahmed@gmail.com'], [$this->user->refresh()->name, $this->user->email]);
    }

    public function test_a_new_email_has_to_be_verified_again_and_the_same_one_does_not(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->ca($this->token, 'POST', '/profile/update', ['email' => 'ahmed@gmail.com'])->assertOk();
        $this->assertNotNull($this->user->refresh()->email_verified_at, 'Sending the same address back changes nothing');

        $this->ca($this->token, 'POST', '/profile/update', ['email' => 'new@gmail.com'])->assertOk()->assertJsonPath('data.email_verified', false);
        $this->assertNull($this->user->refresh()->email_verified_at);
    }

    public function test_an_email_someone_else_uses_is_refused_and_one_freed_by_a_deleted_account_is_free(): void
    {
        $this->makeCustomer(['email' => 'taken@gmail.com']);
        [$trashed] = $this->makeCustomer(['email' => 'trashed@gmail.com']);
        $trashed->delete();   // soft-deleted but not anonymised: the address is still held in the database
        [, $leaver] = $this->makeCustomer(['email' => 'gone@gmail.com']);
        app(AccountDeletion::class)->delete($leaver);   // the real deletion lets go of the address

        $this->ca($this->token, 'POST', '/profile/update', ['email' => 'taken@gmail.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->ca($this->token, 'POST', '/profile/update', ['email' => 'trashed@gmail.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->ca($this->token, 'POST', '/profile/update', ['email' => 'gone@gmail.com'])->assertOk();
    }

    public function test_the_notification_preferences_can_be_changed_one_at_a_time_as_json_or_as_a_form(): void
    {
        $this->ca($this->token, 'POST', '/profile/update', ['notifications' => ['sms' => true]])->assertOk()->assertJsonPath('data.notifications', ['email' => true, 'sms' => true, 'marketing' => true]);

        $this->caFiles($this->token, '/profile/update', ['notifications' => ['email' => 'false', 'marketing' => 'false']])->assertOk()->assertJsonPath('data.notifications', ['email' => false, 'sms' => true, 'marketing' => false]);

        $this->assertSame([false, true, false], [(bool) $this->customer->refresh()->notify_email, (bool) $this->customer->notify_sms, (bool) $this->customer->notify_marketing]);
    }

    public function test_an_avatar_is_a_real_image_upload_stored_under_our_name_and_replaced_cleanly(): void
    {
        $first = $this->caFiles($this->token, '/profile/update', ['avatar' => $this->png()])->assertOk()->json('data.avatar');
        $path = $this->user->refresh()->avatar_path;

        $this->assertStringStartsWith('avatars/', $path);
        $this->assertStringEndsWith($path, $first);
        Storage::disk('public')->assertExists($path);

        $this->caFiles($this->token, '/profile/update', ['avatar' => $this->png('other.png')])->assertOk();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($this->user->refresh()->avatar_path);
    }

    public function test_a_bad_profile_update_is_refused(): void
    {
        foreach ([['name' => ''], ['name' => 'A'], ['email' => 'not-an-email'], ['notifications' => 'yes'], ['notifications' => ['sms' => 'maybe']]] as $bad) {
            $this->ca($this->token, 'POST', '/profile/update', $bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        foreach ([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), UploadedFile::fake()->create('big.png', 3000, 'image/png')] as $file) {
            $this->caFiles($this->token, '/profile/update', ['avatar' => $file])->assertStatus(422)->assertJsonValidationErrors('avatar');
        }
        $this->assertNull($this->user->refresh()->avatar_path);
    }

    // --- Signing out ------------------------------------------------------------------------------------------------

    public function test_signing_out_ends_this_device_only(): void
    {
        $phone = $this->tokenFor($this->user, 'customer');

        $r = $this->ca($this->token, 'POST', '/auth/logout')->assertOk();

        $this->assertSame(['Logged out successfully', null], [$r->json('message'), $r->json('data')]);
        $this->ca($this->token, 'GET', '/profile')->assertUnauthorized();
        $this->ca($phone, 'GET', '/profile')->assertOk();
    }

    // --- Deleting the account ---------------------------------------------------------------------------------------

    public function test_deleting_has_to_be_asked_for_in_so_many_words(): void
    {
        foreach ([[], ['confirm' => false], ['confirm' => 'no']] as $body) {
            $this->ca($this->token, 'DELETE', '/profile', $body)->assertStatus(422)->assertJsonValidationErrors('confirm');
        }

        $this->assertNull($this->user->refresh()->deleted_at);
    }

    public function test_deleting_removes_the_person_and_keeps_only_what_must_be_kept_without_them(): void
    {
        $this->a['activity']->update(['status' => 'published']);
        $completed = $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed', 1500, ['contact_name' => 'Ahmed', 'contact_phone' => '+966501234567', 'contact_email' => 'a@b.c']);
        $this->makePayment($completed, 1500);
        $this->makeReview($this->a['activity'], $this->customer, 5, 'Great');
        Favorite::create(['customer_id' => $this->customer->id, 'activity_id' => $this->a['activity']->id]);
        Device::create(['user_id' => $this->user->id, 'fcm_token' => 'tok', 'platform' => 'android']);
        Conversation::create(['customer_id' => $this->customer->id, 'provider_id' => $this->a['provider']->id, 'activity_id' => $this->a['activity']->id]);
        $this->user->update(['avatar_path' => 'avatars/me.png']);
        Storage::disk('public')->put('avatars/me.png', 'x');

        $r = $this->ca($this->token, 'DELETE', '/profile', ['confirm' => true])->assertOk();

        $this->assertSame(['Account deleted', null], [$r->json('message'), $r->json('data')]);
        $user = User::withTrashed()->find($this->user->id);
        $this->assertNotNull($user->deleted_at);
        $this->assertSame(['Deleted customer', null, null, 'inactive'], [$user->name, $user->email, $user->avatar_path, $user->status]);
        $this->assertStringStartsWith('deleted:', $user->phone);
        $this->assertNull(User::where('phone', '+966501234567')->first(), 'The number can sign up again');
        Storage::disk('public')->assertMissing('avatars/me.png');
        $this->assertSame([0, 0, 0, 0], [Favorite::count(), Device::count(), Conversation::count(), $user->tokens()->count()]);
        $booking = Reservation::find($completed);
        $this->assertSame([null, null, null], [$booking->contact_name, $booking->contact_phone, $booking->contact_email], 'The booking stays for the accounts, without the person');
        $this->assertEquals(1500, Payment::firstOrFail()->amount);
        $this->assertSame(1, Review::count());
        $this->assertSame('Deleted customer', $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews")->json('data.0.name'));
        $this->ca($this->token, 'GET', '/profile')->assertUnauthorized();
    }

    public function test_a_trip_still_to_come_or_a_payment_still_to_finish_stops_the_deletion(): void
    {
        $upcoming = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');

        $r = $this->ca($this->token, 'DELETE', '/profile', ['confirm' => true])->assertStatus(409);
        $this->assertSame('ACCOUNT_HAS_ACTIVE_BOOKINGS', $r->json('code'));

        Reservation::find($upcoming)->update(['status' => 'cancelled']);
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, ['hold_expires_at' => now()->addMinutes(10)->toDateTimeString()]);
        $this->ca($this->token, 'DELETE', '/profile', ['confirm' => true])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_ACTIVE_BOOKINGS');

        $this->assertNull($this->user->refresh()->deleted_at);
    }

    public function test_finished_cancelled_expired_and_lapsed_bookings_do_not_stop_it(): void
    {
        $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'cancelled');
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'expired');
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, ['hold_expires_at' => now()->subMinutes(10)->toDateTimeString()]);

        $this->ca($this->token, 'DELETE', '/profile', ['confirm' => true])->assertOk();
    }

    public function test_a_phone_that_also_has_a_provider_account_cannot_delete_just_the_customer_side(): void
    {
        $this->a['provider']->update(['user_id' => $this->user->id]);

        $this->ca($this->token, 'DELETE', '/profile', ['confirm' => true])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_IS_PROVIDER');

        $this->assertNull($this->user->refresh()->deleted_at);
    }

    public function test_deleting_is_limited_to_three_a_minute(): void
    {
        foreach (range(1, 3) as $i) {
            $this->ca($this->token, 'DELETE', '/profile', [])->assertStatus(422);
        }

        $this->ca($this->token, 'DELETE', '/profile', ['confirm' => true])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
