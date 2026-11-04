<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Activities. About 62% published, 12% draft, 10% waiting for review, 6% rejected, 10% inactive, and 3% of all of
 * them soft-deleted. An activity of an approved provider can be in any state; an unapproved provider only has
 * drafts; when a provider is suspended or deleted, what was published comes off the site (inactive) and the rest stays as it was. Ratings start at zero:
 * BulkReviewSeeder fills them in from the reviews.
 *
 * Volume at scale 1: 15,000, over every category and twelve cities.
 * Prerequisites: BulkProviderSeeder, and categories (CategorySeeder or BulkCategorySeeder).
 */
class BulkActivitySeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('providers', 'BulkProviderSeeder');
        $this->needs('categories', 'CategorySeeder or BulkCategorySeeder', fn ($q) => $q->where('status', 'active'));

        $providers = DB::table('providers')
            ->join('users', 'users.id', '=', 'providers.user_id')
            ->where(fn ($q) => $q->where('users.phone', 'like', self::PROVIDER_PHONE_PREFIX.'%')->orWhere('users.phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%'))
            ->get(['providers.id', 'providers.city', 'providers.area', 'providers.lat', 'providers.lng', 'providers.verification_status', 'providers.created_at',
                'users.status as user_status', 'users.deleted_at as user_deleted_at'])->all();
        $approved = array_values(array_filter($providers, fn ($p) => $p->verification_status === 'verified' && $p->user_status === 'active' && $p->user_deleted_at === null));
        $categories = DB::table('categories')->where('status', 'active')->whereNull('deleted_at')->get(['id', 'name'])->all();

        $this->insert('activities', $this->rows($this->scaled(15000), $providers, $approved, $categories));
    }

    private function rows(int $count, array $providers, array $approved, array $categories): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            // Almost all belong to an approved provider.
            $provider = $approved && $this->chance(96) ? $this->pick($approved) : $this->pick($providers);
            $category = $this->pick($categories);
            $title = $this->activityTitle($category->name);

            $earliest = Carbon::parse($provider->created_at)->addDays(2);
            $created = $earliest->copy()->addDays($this->rand(0, 420));
            // At least three days old, so everything that follows an activity's creation (its bookings, a confirmation
            // minutes later, a hold that runs out) is comfortably in the past.
            $created = $created->greaterThan($this->now->copy()->subDays(3)) ? $this->now->copy()->subDays($this->rand(3, 30)) : $created;

            $status = $provider->verification_status !== 'verified'
                ? 'draft'
                : $this->weighted(['published' => 62, 'draft' => 12, 'pending_review' => 10, 'rejected' => 6, 'inactive' => 10]);

            // As the app does when a provider is suspended or deleted: only what was PUBLISHED comes off the site.
            // Drafts and the rest stay as they were.
            if ($status === 'published' && ($provider->user_status !== 'active' || $provider->user_deleted_at !== null)) {
                $status = 'inactive';
            }

            $deleted = $this->chance(3);
            $status = $deleted ? 'inactive' : $status;

            $published = $status === 'published' ? $created->copy()->addDays($this->rand(1, 10)) : null;
            if ($published && $published->greaterThan($this->now)) {
                $published = $this->now->copy();
            }

            yield [
                'uuid' => $this->uuid('activity', $i),
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => $title,
                'slug' => Str::slug($title).self::ACTIVITY_SLUG_MARK.$i,
                'description' => $this->activityDescription($category->name, $provider->city ?? 'Hurghada'),
                'location' => trim(($provider->area ?? '').', '.($provider->city ?? ''), ', '),
                'meeting_point' => $this->pick(['Marina entrance', 'Main jetty', 'Hotel lobby pickup', 'Beach club reception', 'Port gate 2']),
                'lat' => round((float) $provider->lat + $this->rand(-500, 500) / 10000, 6),
                'lng' => round((float) $provider->lng + $this->rand(-500, 500) / 10000, 6),
                'duration_minutes' => $this->pick([60, 90, 120, 180, 240, 300, 360, 480]),
                'min_participants' => $this->pick([1, 1, 1, 2]),
                // At least 12, which the reservation seeder relies on to keep a day within capacity.
                'max_participants' => $this->rand(12, 40),
                'average_rating' => 0,
                'reviews_count' => 0,
                'min_age' => $this->pick([null, null, 6, 8, 12, 16]),
                'status' => $status,
                'rejection_reason' => $status === 'rejected' ? $this->pick($this->rejectionReasons()) : null,
                'published_at' => $published ? $this->ts($published) : null,
                'deleted_at' => $deleted ? $this->ts($created->copy()->addDays($this->rand(20, 200))->min($this->now)) : null,
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($created),
            ];
        }
    }
}
