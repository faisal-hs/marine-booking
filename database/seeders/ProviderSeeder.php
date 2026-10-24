<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesToRunInProduction;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProviderSeeder extends Seeder
{
    use RefusesToRunInProduction;

    /**
     * Seeds 22 realistic, distinct providers (business + owner pairs) so
     * ActivitySeeder has a real pool to distribute activities across
     * instead of everything belonging to one demo provider. Each provider
     * is passwordless (per Module 01's phone+OTP model) but has
     * phone_verified_at set, matching what ProviderAuthService::verifyOtp()
     * produces for a real registration.
     */
    public function run(): void
    {
        $this->refuseInProduction();

        $businesses = [
            ['owner' => 'Ahmed Nasser', 'business' => 'Red Sea Diving Pros', 'city' => 'Sharm El Sheikh'],
            ['owner' => 'Sara Youssef', 'business' => 'Blue Reef Diving Center', 'city' => 'Hurghada'],
            ['owner' => 'Karim Adel', 'business' => 'Dahab Freedivers', 'city' => 'Dahab'],
            ['owner' => 'Mona Hassan', 'business' => 'Coral Garden Snorkeling', 'city' => 'Marsa Alam'],
            ['owner' => 'Tarek Fahmy', 'business' => 'Deep Blue Expeditions', 'city' => 'Sharm El Sheikh'],
            ['owner' => 'Laila Ibrahim', 'business' => 'Sinai Sea Safaris', 'city' => 'Nuweiba'],
            ['owner' => 'Omar Khalil', 'business' => 'Nile Fishing Charters', 'city' => 'Aswan'],
            ['owner' => 'Nour El-Din', 'business' => 'Alexandria Angling Co', 'city' => 'Alexandria'],
            ['owner' => 'Yasmin Saad', 'business' => 'Golden Coast Boat Trips', 'city' => 'Hurghada'],
            ['owner' => 'Hassan Aly', 'business' => 'Sultan Yacht Charters', 'city' => 'Sharm El Sheikh'],
            ['owner' => 'Dina Farouk', 'business' => 'Windward Sailing Club', 'city' => 'El Gouna'],
            ['owner' => 'Mostafa Reda', 'business' => 'Skyline Parasail', 'city' => 'Hurghada'],
            ['owner' => 'Salma Tawfik', 'business' => 'Aqua Jet Rentals', 'city' => 'Sharm El Sheikh'],
            ['owner' => 'Amr Sabry', 'business' => 'Mangrove Kayak Tours', 'city' => 'Nabq Bay'],
            ['owner' => 'Rana Mahmoud', 'business' => 'Desert Sea Safari Co', 'city' => 'Marsa Alam'],
            ['owner' => 'Khaled Zaki', 'business' => 'Island Hopper Tours', 'city' => 'Hurghada'],
            ['owner' => 'Heba Younis', 'business' => 'Dolphin House Encounters', 'city' => 'Sataya'],
            ['owner' => 'Wael Ashraf', 'business' => 'Southern Star Sailing', 'city' => 'El Gouna'],
            ['owner' => 'Nadia Kamal', 'business' => 'Reef & Wreck Diving', 'city' => 'Hurghada'],
            ['owner' => 'Fady Maher', 'business' => 'Bedouin Sea Adventures', 'city' => 'Nuweiba'],
            ['owner' => 'Rania Hosny', 'business' => 'Emerald Coast Snorkel Tours', 'city' => 'Marsa Alam'],
            ['owner' => 'Sami Gaber', 'business' => 'Anchor Point Watersports', 'city' => 'Dahab'],
        ];

        $verificationStatuses = ['verified', 'verified', 'verified', 'pending', 'rejected'];

        foreach ($businesses as $index => $entry) {
            $phone = '05' . str_pad((string) (10000000 + $index), 8, '0', STR_PAD_LEFT);

            $user = User::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $entry['owner'],
                    'user_type' => 'provider',
                    'status' => 'active',
                    'phone_verified_at' => now(),
                ]
            );

            Provider::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $entry['business'],
                    'slug' => Str::slug($entry['business']),
                    'bio' => "{$entry['business']} offers guided marine excursions departing from {$entry['city']}, run by a locally based team of certified professionals.",
                    'address' => "{$entry['city']} Marina",
                    'lat' => round(27.9 + (($index % 10) - 5) * 0.35, 6),
                    'lng' => round(33.8 + (($index % 8) - 4) * 0.4, 6),
                    'verification_status' => $verificationStatuses[$index % count($verificationStatuses)],
                    'commission_rate' => [8, 10, 12, 15][$index % 4],
                ]
            );
        }

        $this->command->info('Providers seeded: ' . count($businesses));
    }
}
