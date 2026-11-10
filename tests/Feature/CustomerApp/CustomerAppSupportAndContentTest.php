<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Provider;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** Writing to support, and the pages, FAQs, support details and "about" screen. */
class CustomerAppSupportAndContentTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        Storage::fake('local');
        Storage::fake('public');
        $this->a = $this->approvedProvider();
        [, $this->customer, $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ticket(array $over = [], ?string $token = null)
    {
        return $this->caFiles($token ?? $this->token, '/support/tickets', $over + ['topic' => 'booking', 'message' => 'I paid but the booking still shows pending']);
    }

    // --- Support tickets --------------------------------------------------------------------------------------------

    public function test_a_ticket_is_created_and_the_answer_has_the_specifications_shape(): void
    {
        $booking = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending');

        $r = $this->ticket(['booking_id' => $booking])->assertCreated();

        $this->assertSame('We received your message', $r->json('message'));
        $this->assertSame(['ticket_id', 'reference', 'created_at'], array_keys($r->json('data')));
        $ticket = SupportTicket::firstOrFail();
        $this->assertSame([$ticket->id, $ticket->uuid, '2026-11-15T12:00:00Z'], [$r->json('data.ticket_id'), $r->json('data.reference'), $r->json('data.created_at')]);
        $this->assertSame([$this->customer->id, $booking, 'booking', 'open', null], [$ticket->customer_id, $ticket->reservation_id, $ticket->topic, $ticket->status, $ticket->attachment_path]);
    }

    public function test_the_booking_and_the_attachment_are_optional(): void
    {
        $this->ticket(['topic' => 'account', 'message' => 'How do I change my number?'])->assertCreated();

        $this->assertNull(SupportTicket::firstOrFail()->reservation_id);
    }

    public function test_the_topic_and_the_message_are_checked(): void
    {
        foreach ([['topic' => 'weather'], ['topic' => ''], ['message' => ''], ['message' => 'abc'], ['message' => str_repeat('x', 2001)]] as $bad) {
            $this->ticket($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        Cache::flush();   // the five bad ones above used up the minute's five
        foreach (config('customer_app.support_topics') as $topic) {
            $this->ticket(['topic' => $topic])->assertCreated();
        }
        $this->assertSame(5, SupportTicket::count());
    }

    public function test_someone_elses_booking_and_a_made_up_one_are_not_found_alike(): void
    {
        [, $other] = $this->makeCustomer();
        $theirs = $this->makeBooking($this->a, $other, self::NEXT_SATURDAY, 'confirmed');

        $one = $this->ticket(['booking_id' => $theirs])->assertNotFound();
        $two = $this->ticket(['booking_id' => 99999])->assertNotFound();

        $this->assertSame($one->json('message'), $two->json('message'));
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_an_attachment_is_kept_privately_under_our_name_not_the_one_it_came_with(): void
    {
        $file = UploadedFile::fake()->createWithContent('../../my screenshot<1>.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->ticket(['attachment' => $file])->assertCreated();

        $t = SupportTicket::firstOrFail();
        $this->assertStringStartsWith("support-attachments/{$this->customer->uuid}/", $t->attachment_path);
        $this->assertStringNotContainsString('screenshot', $t->attachment_path);
        $this->assertStringNotContainsString('..', $t->attachment_path);
        Storage::disk('local')->assertExists($t->attachment_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Never on the public disk');
        $this->assertStringNotContainsString('/', $t->attachment_name);
        $this->assertStringNotContainsString('<', $t->attachment_name);
    }

    public function test_pictures_and_pdfs_are_accepted_and_nothing_else(): void
    {
        $this->ticket(['attachment' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')])->assertCreated();
        $this->ticket(['attachment' => UploadedFile::fake()->create('shot.jpg', 100, 'image/jpeg')])->assertCreated();

        Cache::flush();
        foreach ([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('page.html', 10, 'text/html'), UploadedFile::fake()->create('huge.pdf', 5121, 'application/pdf')] as $bad) {
            $this->ticket(['attachment' => $bad])->assertStatus(422)->assertJsonValidationErrors('attachment');
        }
        $this->assertSame(2, SupportTicket::count(), 'A refused attachment makes no ticket');
    }

    public function test_a_path_the_client_names_for_the_attachment_is_never_used(): void
    {
        $this->ticket(['attachment' => '/etc/passwd'])->assertStatus(422)->assertJsonValidationErrors('attachment');
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_tickets_are_limited_to_five_a_minute(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ticket()->assertCreated();
        }

        $this->ticket()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    // --- The pages ----------------------------------------------------------------------------------------------------

    public function test_terms_and_privacy_have_a_title_an_update_date_and_ordered_sections(): void
    {
        foreach (['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy'] as $slug => $title) {
            $d = $this->ca(null, 'GET', "/pages/{$slug}")->assertOk()->json('data');

            $this->assertSame(['title', 'updated_at', 'sections'], array_keys($d));
            $this->assertSame([$title, '2026-11-01'], [$d['title'], $d['updated_at']]);
            $this->assertSame(['title', 'body'], array_keys($d['sections'][0]));
        }
        $this->assertSame('Acceptance of Terms', $this->ca(null, 'GET', '/pages/terms')->json('data.sections.0.title'));
    }

    public function test_the_pages_are_in_arabic_when_asked_and_the_update_date_is_a_setting(): void
    {
        config(['customer_app.content_updated_at' => '2026-12-15']);

        $d = $this->ca(null, 'GET', '/pages/privacy', [], ['Accept-Language' => 'ar'])->json('data');

        $this->assertSame(['سياسة الخصوصية', '2026-12-15'], [$d['title'], $d['updated_at']]);
    }

    public function test_the_cancellation_page_is_the_policy_itself(): void
    {
        $d = $this->ca(null, 'GET', '/pages/cancellation-policy')->assertOk()->json('data');

        $this->assertSame(['Cancellation Policy', '2026-11-01'], [$d['title'], $d['updated_at']]);
        $this->assertSame(['Free cancellation', 'Partial refund', 'Late cancellation', 'How refunds work'], array_column($d['sections'], 'title'));
        $this->assertSame('Cancel more than 24 hours before the activity for a full refund.', $d['sections'][0]['body']);
        $this->assertSame('Cancel between 12 and 24 hours before the activity for a 50% refund.', $d['sections'][1]['body']);

        config(['customer_app.cancellation.full_refund_hours' => 72, 'customer_app.cancellation.partial_refund_hours' => 48, 'customer_app.cancellation.partial_refund_percent' => 30]);
        $changed = $this->ca(null, 'GET', '/pages/cancellation-policy')->json('data.sections');
        $this->assertSame('Cancel more than 72 hours before the activity for a full refund.', $changed[0]['body'], 'Change the policy and the page follows');
        $this->assertSame('Cancel between 48 and 72 hours before the activity for a 30% refund.', $changed[1]['body']);

        config(['customer_app.cancellation.partial_refund_percent' => 0]);
        $this->assertSame(['Free cancellation', 'Late cancellation', 'How refunds work'], array_column($this->ca(null, 'GET', '/pages/cancellation-policy')->json('data.sections'), 'title'), 'No partial window, no partial section');
    }

    public function test_the_cancellation_page_in_arabic(): void
    {
        $d = $this->ca(null, 'GET', '/pages/cancellation-policy', [], ['Accept-Language' => 'ar'])->json('data');

        $this->assertSame('سياسة الإلغاء', $d['title']);
        $this->assertStringContainsString('24', $d['sections'][0]['body']);
        $this->assertNotSame('Cancel more than 24 hours before the activity for a full refund.', $d['sections'][0]['body']);
    }

    // --- Support details, FAQs, about ----------------------------------------------------------------------------------

    public function test_the_support_details_are_a_setting_and_an_unset_one_is_null(): void
    {
        $this->assertSame(['support_phone' => null, 'support_email' => null, 'whatsapp' => null], $this->ca(null, 'GET', '/settings')->json('data'));

        config(['customer_app.support' => ['phone' => '+966920000000', 'email' => 'support@bahriyat.sa', 'whatsapp' => '+966550000000']]);

        $this->assertSame(['support_phone' => '+966920000000', 'support_email' => 'support@bahriyat.sa', 'whatsapp' => '+966550000000'], $this->ca(null, 'GET', '/settings')->json('data'));
    }

    public function test_the_faqs_have_the_specifications_fields_a_stable_order_and_a_category_filter(): void
    {
        $all = $this->ca(null, 'GET', '/faqs')->assertOk()->json('data');

        $this->assertSame(['id', 'category', 'question', 'answer'], array_keys($all[0]));
        $this->assertSame(range(1, count($all)), array_column($all, 'id'));
        $this->assertSame($all, $this->ca(null, 'GET', '/faqs')->json('data'), 'The same order every time');
        $bookings = $this->ca(null, 'GET', '/faqs', ['category' => 'bookings'])->json('data');
        $this->assertNotEmpty($bookings);
        $this->assertSame(['bookings'], array_unique(array_column($bookings, 'category')));
        $this->ca(null, 'GET', '/faqs', ['category' => 'weather'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_cancellation_answer_never_contradicts_the_real_policy(): void
    {
        $answer = fn () => collect($this->ca(null, 'GET', '/faqs', ['category' => 'bookings'])->json('data'))->firstWhere('question', 'How do I cancel a booking?')['answer'];

        $this->assertStringContainsString('more than 24 hours', $answer());
        $this->assertStringContainsString('12 and 24 hours', $answer());

        config(['customer_app.cancellation.full_refund_hours' => 6, 'customer_app.cancellation.partial_refund_percent' => 0]);
        $this->assertStringContainsString('more than 6 hours', $answer());
        $this->assertStringNotContainsString('24 hours', $answer());
    }

    public function test_the_faqs_follow_the_language(): void
    {
        $ar = $this->ca(null, 'GET', '/faqs', [], ['Accept-Language' => 'ar'])->json('data');

        $this->assertSame('كيف ألغي الحجز؟', $ar[0]['question']);
    }

    public function test_about_has_real_counts_and_the_version_block_of_the_platform_asked_for(): void
    {
        $this->makeCity('Jeddah');
        $this->makeCity('Yanbu');
        $this->makeCity('Off', 'x', ['is_active' => false]);
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'cancelled');
        Provider::where('id', $this->a['provider']->id)->update(['verification_status' => 'verified']);
        config(['customer_app.about.app_version.ios' => ['latest' => '2.1.0', 'minimum' => '2.0.0', 'store_url' => 'https://apps.apple.com/app/bahriyat'], 'customer_app.about.founded_year' => 2023]);

        $d = $this->ca(null, 'GET', '/about', ['platform' => 'ios'])->assertOk()->json('data');

        $this->assertSame(['mission', 'founded_year', 'providers_count', 'bookings_count', 'cities_count', 'app_version'], array_keys($d));
        $this->assertSame([2023, 1, 2, 2], [$d['founded_year'], $d['providers_count'], $d['bookings_count'], $d['cities_count']], 'Confirmed and completed bookings; verified providers; active cities');
        $this->assertSame(['latest' => '2.1.0', 'minimum' => '2.0.0', 'force_update' => false, 'store_url' => 'https://apps.apple.com/app/bahriyat'], $d['app_version']);
        $this->assertSame('1.0.0', $this->ca(null, 'GET', '/about')->json('data.app_version.latest'), 'Android unless said otherwise');
    }

    public function test_an_app_that_is_older_than_the_minimum_is_told_to_update_and_only_when_it_says_its_version(): void
    {
        config(['customer_app.about.app_version.android' => ['latest' => '2.1.0', 'minimum' => '2.0.0', 'store_url' => null]]);
        $force = fn (array $q) => $this->ca(null, 'GET', '/about', $q + ['platform' => 'android'])->assertOk()->json('data.app_version.force_update');

        $this->assertTrue($force(['version' => '1.9.9']));
        $this->assertTrue($force(['version' => '1.10.0']));
        $this->assertFalse($force(['version' => '2.0.0']));
        $this->assertFalse($force(['version' => '2.1.0']));
        $this->assertFalse($force([]), 'It cannot be told to update if it does not say what it is');
        $this->ca(null, 'GET', '/about', ['platform' => 'windows'])->assertStatus(422);
    }
}
