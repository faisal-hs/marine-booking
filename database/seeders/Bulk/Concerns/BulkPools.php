<?php

namespace Database\Seeders\Bulk\Concerns;

/** The vocabulary the Bulk seeders build realistic-looking data from. Plain lists, no database. */
trait BulkPools
{
    /** @return list<array{0: string, 1: list<string>, 2: float, 3: float}> [city, areas, lat, lng] */
    protected function cities(): array
    {
        return [
            ['Hurghada', ['Sakkala', 'El Dahar', 'Intercontinental Road', 'Marina'], 27.2579, 33.8116],
            ['Sharm El Sheikh', ['Naama Bay', 'Hadaba', 'Shark\'s Bay', 'Nabq'], 27.9158, 34.3300],
            ['Dahab', ['Lighthouse', 'Blue Lagoon', 'Masbat', 'Assalah'], 28.5091, 34.5136],
            ['Marsa Alam', ['Abu Dabbab', 'Port Ghalib', 'Wadi El Gemal'], 25.0676, 34.8790],
            ['El Gouna', ['Abu Tig Marina', 'Downtown', 'West Golf'], 27.3955, 33.6785],
            ['Nuweiba', ['Tarabin', 'Port', 'Maagana'], 29.0333, 34.6667],
            ['Alexandria', ['Corniche', 'Montaza', 'Stanley', 'Abu Qir'], 31.2001, 29.9187],
            ['Aswan', ['Elephantine Island', 'Corniche', 'Nubian Village'], 24.0889, 32.8998],
            ['Jeddah', ['North Obhur', 'Corniche', 'Al Shati', 'South Obhur'], 21.5433, 39.1728],
            ['Yanbu', ['Old Town', 'Al Bahr', 'Marina'], 24.0895, 38.0618],
            ['Aqaba', ['South Beach', 'Tala Bay', 'Marina'], 29.5321, 35.0063],
            ['Ras Sudr', ['Beach Road', 'Lagoon'], 29.5900, 32.7000],
        ];
    }

    protected function businessName(): string
    {
        $first = ['Blue', 'Coral', 'Deep', 'Golden', 'Red Sea', 'Azure', 'Turquoise', 'Emerald', 'Sunrise', 'Sunset', 'Reef', 'Dolphin', 'Pearl', 'Lagoon', 'Anchor', 'Windward', 'Horizon', 'Crescent', 'Palm', 'Sandbar'];
        $second = ['Divers', 'Sailing Club', 'Charters', 'Expeditions', 'Watersports', 'Boat Trips', 'Adventures', 'Marine Tours', 'Safaris', 'Yachts', 'Snorkel Tours', 'Water Sports Co', 'Cruises', 'Dive Center'];

        return $this->pick($first).' '.$this->pick($second);
    }

    protected function providerBio(string $business, string $city): string
    {
        return $this->pick([
            "{$business} runs guided marine excursions from {$city}, led by a local team of certified professionals.",
            "Family-run since the 2000s, {$business} takes small groups out from {$city} with all safety equipment included.",
            "{$business} is a licensed operator in {$city} focused on small groups, safety and unhurried trips.",
            "From the marina in {$city}, {$business} offers daily departures for beginners and experienced guests alike.",
        ]);
    }

    protected function activityTitle(string $category): string
    {
        $before = ['Sunrise', 'Sunset', 'Private', 'Family', 'Premium', 'Classic', 'Full Day', 'Half Day', 'Beginner', 'Advanced', 'VIP', 'Weekend', 'Hidden Reef', 'Open Water', 'Relaxed'];
        $after = ['Adventure', 'Experience', 'Escape', 'Expedition', 'Trip', 'Tour', 'Day Out', 'Journey', 'Outing', 'Session'];

        return $this->pick($before).' '.$category.' '.$this->pick($after);
    }

    protected function activityDescription(string $category, string $city): string
    {
        return $this->pick([
            "Join a small group for an unforgettable {$category} outing in {$city}. All equipment and a friendly, experienced crew are included.",
            "A relaxed {$category} experience suitable for first-timers, with clear briefings, safety gear and plenty of time on the water near {$city}.",
            "Discover the best of {$city} on this {$category} trip. Hotel pickup can be arranged, and light refreshments are served on board.",
            "Our most popular {$category} departure from {$city}: small groups, calm waters and a crew that knows every spot worth stopping at.",
        ]).' '.$this->pick([
            'Please arrive 30 minutes before departure.',
            'Wear light clothing and bring sun protection.',
            'Bring a towel and a change of clothes.',
            'Trips run in all but the roughest weather.',
        ]);
    }

    /** @return list<string> */
    protected function requirementPool(): array
    {
        return ['Basic swimming ability', 'Minimum age as stated', 'Bring a towel and swimwear', 'Valid ID or passport', 'Sun protection (hat and sunscreen)', 'Signed liability waiver',
            'Good general health', 'Closed-toe shoes for the boat', 'No alcohol before the trip', 'Advance booking at least 24 hours before', 'Valid diving certification', 'Arrive 30 minutes early'];
    }

    /** @return list<string> */
    protected function inclusionPool(): array
    {
        return ['All safety equipment', 'Professional guide', 'Snorkelling gear', 'Soft drinks and water', 'Hotel pickup and drop-off', 'Lunch on board', 'Fresh fruit', 'Underwater photos',
            'Towels', 'Life jackets', 'Fuel and port fees', 'Insurance'];
    }

    /** @return list<string> */
    protected function exclusionPool(): array
    {
        return ['Gratuities', 'Personal expenses', 'Alcoholic drinks', 'Souvenir photos', 'Travel insurance', 'Park entrance fees', 'Equipment rental upgrades'];
    }

    /** @return list<string> */
    protected function addOnPool(): array
    {
        return ['Underwater photo package', 'Lunch box', 'Extra hour', 'Private guide', 'Hotel pickup', 'GoPro rental', 'Wetsuit rental', 'Souvenir t-shirt', 'Sunset drinks'];
    }

    /** @return list<string> */
    protected function scheduleNotes(): array
    {
        return ['Arrive 30 minutes before departure.', 'Meet at the marina entrance.', 'Weather permitting.', 'Late arrivals cannot be accommodated.', 'Pickup times are confirmed the day before.'];
    }

    /** @return list<string> */
    protected function rejectionReasons(): array
    {
        return ['The description is too short to review.', 'Please add at least three photos.', 'Pricing is missing for children.', 'The meeting point is unclear.',
            'The safety information is incomplete.', 'The category does not match the activity.'];
    }

    /** @return list<string> */
    protected function providerRejectionReasons(): array
    {
        return ['The commercial registration has expired.', 'The insurance certificate is unreadable.', 'The safety certification does not cover water activities.',
            'The documents do not match the business name.', 'Please upload clearer scans of all three documents.'];
    }

    /** @return array<int, list<string>> review comments by star rating */
    protected function reviewComments(): array
    {
        return [
            1 => ['Disappointing. The trip started late and the crew seemed rushed.', 'Not what was described. I would not book again.'],
            2 => ['Below expectations, though the guide was friendly.', 'Overcrowded, and the equipment was worn.'],
            3 => ['Decent trip, but nothing special for the price.', 'Good crew, a bit rushed, average overall.'],
            4 => ['Really enjoyed it. Friendly crew and well organised.', 'Great value and a lovely day out. Would recommend.', 'Beautiful spots, and the guide was patient with beginners.'],
            5 => ['Absolutely fantastic. The best day of our holiday!', 'Perfectly organised, spotless equipment, amazing views. Five stars.', 'The crew went out of their way for our kids. Will book again.', 'رحلة رائعة وطاقم محترف جداً، أنصح بها بشدة.'],
        ];
    }

    /** @return list<string> */
    protected function failureReasons(): array
    {
        return ['Card declined', 'Insufficient funds', 'Payment timed out', 'Issuer unavailable', '3-D Secure check failed'];
    }

    /** @return list<array{0: string, 1: string}> [title, body] */
    protected function broadcastTexts(): array
    {
        return [
            ['Winter schedule', 'Boats leave an hour later from December. Check your booking for the new time.'],
            ['New destinations', 'We have added trips from Marsa Alam and Dahab. Take a look at the new activities.'],
            ['Weather advisory', 'Strong winds are forecast this weekend. Some departures may be rescheduled.'],
            ['Thank you', 'Thanks for travelling with us this season. We hope to see you on the water again.'],
            ['جدول الشتاء', 'تغادر القوارب بعد ساعة من موعدها المعتاد ابتداءً من ديسمبر. راجع حجزك للتفاصيل.'],
            ['Provider update', 'Remember to keep your documents up to date. Expired documents can pause your listings.'],
            ['Holiday offers', 'Special family prices on selected trips during the holidays.'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: list<array{key: string, label: string}>|null}> [key, label, type, options] */
    protected function featureTemplates(): array
    {
        $opts = fn (array $keys) => array_map(fn ($k) => ['key' => $k, 'label' => ucfirst(str_replace('_', ' ', $k))], $keys);

        return [
            ['difficulty', 'Difficulty', 'select', $opts(['beginner', 'intermediate', 'advanced'])],
            ['max_depth_m', 'Maximum depth (m)', 'number', null],
            ['max_group_size', 'Maximum group size', 'number', null],
            ['equipment_included', 'Equipment included', 'boolean', null],
            ['certification_required', 'Certification required', 'boolean', null],
            ['pickup_available', 'Hotel pickup available', 'boolean', null],
            ['languages', 'Languages', 'multi_select', $opts(['en', 'ar', 'de', 'ru', 'fr', 'it'])],
            ['meals', 'Meals', 'select', $opts(['none', 'snack', 'lunch', 'full_meal'])],
            ['best_season', 'Best season', 'multi_select', $opts(['spring', 'summer', 'autumn', 'winter'])],
            ['highlights', 'Highlights', 'text', null],
            ['boat_type', 'Boat type', 'select', $opts(['speedboat', 'yacht', 'glass_bottom', 'dhow'])],
        ];
    }

    /** @return list<string> forty extra categories, on top of the twelve the demo data has */
    protected function extraCategoryNames(): array
    {
        return ['Kitesurfing', 'Windsurfing', 'Stand-Up Paddleboarding', 'Scuba Courses', 'Freediving Courses', 'Night Diving', 'Wreck Diving', 'Banana Boat', 'Water Skiing', 'Wakeboarding',
            'Flyboarding', 'Glass-Bottom Boat', 'Submarine Tours', 'Sunset Cruises', 'Dinner Cruises', 'Deep-Sea Fishing', 'Fly Fishing', 'Spearfishing', 'Sea Turtle Spotting', 'Whale Watching',
            'Bird Watching Cruises', 'Mangrove Tours', 'Lagoon Tours', 'Coastal Hiking and Swim', 'Beach Camping', 'Snorkel Safari', 'Private Island Day', 'Pearl Diving Experience', 'Surf Lessons', 'Bodyboarding',
            'Canoeing', 'Rowing Tours', 'Catamaran Trips', 'Speedboat Rides', 'Jet Boat Thrills', 'Underwater Photography', 'Marine Biology Tours', 'Cave Snorkelling', 'Reef Restoration Dives', 'Yoga on the Water'];
    }

    /** @return list<string> */
    protected function staffJobTitles(): array
    {
        return ['Operations Manager', 'Support Agent', 'Content Editor', 'Finance Analyst', 'Provider Onboarding Lead', 'Customer Success', 'Quality Reviewer', 'Regional Coordinator'];
    }

    protected function fcmToken(): string
    {
        return substr(str_repeat(substr(sha1((string) $this->raw().(string) $this->raw()), 0, 38), 4), 0, 152);
    }
}
