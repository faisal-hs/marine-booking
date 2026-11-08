<?php

namespace Tests\Feature\Database;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Admins no longer approve activities, so `pending_review` and `rejected` can no longer be reached. The migration that retires them moves every
 * activity still there to what it would be today, and must never touch an activity in any other state.
 */
class RetireActivityReviewStatesMigrationTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_09_100100_retire_the_activity_review_states.php'))->up();
    }

    private function seedStates(): array
    {
        [, $provider] = $this->makeProvider('verified');

        return collect(['pending_review', 'rejected', 'draft', 'approved', 'published', 'inactive'])
            ->mapWithKeys(fn ($status) => [$status => $this->makeActivity($provider, $status, $status === 'rejected' ? ['rejection_reason' => 'Photos too dark'] : [])])->all();
    }

    public function test_the_suspension_columns_exist(): void
    {
        foreach (['suspended_at', 'suspension_reason', 'suspended_by_user_id', 'suspended_from_status'] as $column) {
            $this->assertTrue(Schema::hasColumn('activities', $column), $column);
        }
    }

    public function test_with_automatic_publishing_on_a_waiting_activity_goes_live(): void
    {
        config(['activities.auto_publish' => true]);
        $a = $this->seedStates();

        $this->migrate();

        $waiting = $a['pending_review']->refresh();
        $this->assertSame('published', $waiting->status);
        $this->assertNotNull($waiting->published_at);
        $this->assertNull($waiting->rejection_reason);
    }

    public function test_a_waiting_activity_keeps_the_publication_date_it_already_had(): void
    {
        config(['activities.auto_publish' => true]);
        $a = $this->seedStates();
        $a['pending_review']->update(['published_at' => '2026-03-01 10:00:00']);

        $this->migrate();

        $this->assertSame('2026-03-01 10:00:00', $a['pending_review']->refresh()->published_at->toDateTimeString());
    }

    public function test_with_automatic_publishing_off_a_waiting_activity_becomes_ready_and_hidden(): void
    {
        config(['activities.auto_publish' => false]);
        $a = $this->seedStates();

        $this->migrate();

        $waiting = $a['pending_review']->refresh();
        $this->assertSame('approved', $waiting->status);
        $this->assertNull($waiting->published_at, 'It has not been published');
    }

    public function test_a_rejected_activity_becomes_a_draft_without_its_reason(): void
    {
        $a = $this->seedStates();

        $this->migrate();

        $rejected = $a['rejected']->refresh();
        $this->assertSame('draft', $rejected->status);
        $this->assertNull($rejected->rejection_reason);
    }

    public function test_every_other_activity_is_left_exactly_as_it_was(): void
    {
        $a = $this->seedStates();
        $before = collect(['draft', 'approved', 'published', 'inactive'])->mapWithKeys(fn ($s) => [$s => $a[$s]->refresh()->only(['status', 'published_at', 'updated_at'])])->all();

        $this->migrate();

        foreach ($before as $status => $row) {
            $this->assertEquals($row, $a[$status]->refresh()->only(['status', 'published_at', 'updated_at']), "A {$status} activity is untouched");
        }
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $a = $this->seedStates();
        $this->migrate();
        $once = collect($a)->map(fn ($activity) => $activity->refresh()->only(['status', 'published_at']))->all();

        $this->migrate();

        $this->assertEquals($once, collect($a)->map(fn ($activity) => $activity->refresh()->only(['status', 'published_at']))->all());
    }

    public function test_nothing_is_left_in_a_retired_state(): void
    {
        $this->seedStates();

        $this->migrate();

        $this->assertSame(0, Activity::whereIn('status', ['pending_review', 'rejected'])->count());
    }
}
