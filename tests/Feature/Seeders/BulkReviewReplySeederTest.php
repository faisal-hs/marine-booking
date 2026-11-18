<?php

namespace Tests\Feature\Seeders;

use Carbon\Carbon;
use Database\Seeders\Bulk\BulkReviewReplySeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesSeededBulkDatabase;
use Tests\TestCase;

/**
 * BulkReviewReplySeeder answers some of the bulk reviews. What a review's reply is (when it was written, what it says, whether it was later deleted) must be a matter of THAT
 * review alone, never of how many other reviews were answered before it: otherwise a review whose reply was not yet due is re-rolled on a second run with different numbers (the
 * reviews ahead of it, now answered, no longer draw any), and a reply appears that the first run said was not due. It showed up now and then in "running everything again adds
 * nothing", depending on the dates in the data.
 */
class BulkReviewReplySeederTest extends TestCase
{
    use UsesSeededBulkDatabase;

    protected function bulkScale(): float
    {
        return (float) (getenv('BULK_TEST_SCALE') ?: 0.05);
    }

    private function replies(): array
    {
        return DB::table('review_replies')->orderBy('review_id')->orderBy('created_at')->orderBy('is_active')
            ->get(['review_id', 'provider_id', 'text', 'is_active', 'deleted_at', 'deleted_by_user_id', 'created_at', 'updated_at'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_a_reply_is_the_same_whenever_it_is_made_whatever_was_answered_before_it(): void
    {
        // One fixed instant, after all the data, for every run: what is "due" is then the same each time, and only the draws could differ.
        Carbon::setTestNow($this->bulkSeededAt()->addMinutes(10));
        DB::beginTransaction();
        try {
            DB::table('review_replies')->delete();
            $this->seed(BulkReviewReplySeeder::class);
            $all = $this->replies();

            // Half of the answered reviews lose their replies, and the seeder is run again: they are made afresh, with the other half already answered ahead of (and between) them.
            $answered = DB::table('review_replies')->distinct()->orderBy('review_id')->pluck('review_id')->all();
            DB::table('review_replies')->whereIn('review_id', array_slice($answered, 0, intdiv(count($answered), 2)))->delete();
            $this->seed(BulkReviewReplySeeder::class);
            $again = $this->replies();
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        $this->assertGreaterThan(40, count($all), 'There are replies to compare');
        $this->assertSame($all, $again, 'Every reply came out exactly as it had: the same time, the same words, the same deletions');
    }

    public function test_running_it_again_adds_nothing_even_for_the_reviews_whose_reply_is_not_due_yet(): void
    {
        Carbon::setTestNow($this->bulkSeededAt()->addMinutes(10));
        DB::beginTransaction();
        try {
            DB::table('review_replies')->delete();
            $this->seed(BulkReviewReplySeeder::class);
            $first = $this->replies();
            $this->seed(BulkReviewReplySeeder::class);
            $this->seed(BulkReviewReplySeeder::class);
            $third = $this->replies();
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        $this->assertSame($first, $third);
    }
}
