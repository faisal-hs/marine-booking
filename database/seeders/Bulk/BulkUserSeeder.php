<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\Hash;

/**
 * The user rows behind every account. One person per row; customer, provider and staff accounts are
 * told apart by phone range (07..., 06..., 08...), so they never collide with the demo accounts (05...).
 *
 * Volume at scale 1: 25,000 customers, 1,500 providers, 40 staff (never fewer than 6, so every role exists).
 * Customers: about 2.5% suspended and 1% soft-deleted; about 30% have Arabic names. Providers: 1% suspended,
 * 0.5% deleted. Staff all sign in with the password "password" (the demo accounts do too).
 * Prerequisites: none.
 */
class BulkUserSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $customers = $this->scaled(25000);
        $providers = $this->scaled(1500);
        $staff = max(6, $this->scaled(40));

        $this->insert('users', $this->customers($customers));
        $this->insert('users', $this->providers($providers));
        $this->insert('users', $this->staff($staff));
    }

    private function phone(string $prefix, int $i): string
    {
        return $prefix.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
    }

    /** [status, deleted_at] from one roll, so the shares are exact over a large set. */
    private function standing(int $deletedPerThousand, int $suspendedPerThousand): array
    {
        $roll = $this->rand(1, 1000);

        return match (true) {
            $roll <= $deletedPerThousand => ['active', $this->ts($this->daysAgo(1, 200))],
            $roll <= $deletedPerThousand + $suspendedPerThousand => ['suspended', null],
            default => ['active', null],
        };
    }

    private function customers(int $count): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            $arabic = $this->chance(30);
            $created = $this->daysAgo(30, 700);
            // Whatever the scale, there is always one suspended and one deleted customer to exercise those cases.
            [$status, $deleted] = match (true) {
                $i === 5 && $count > 12 => ['suspended', null],
                $i === 11 && $count > 12 => ['active', $this->ts($this->daysAgo(1, 200))],
                default => $this->standing(10, 25),
            };

            yield [
                'uuid' => $this->uuid('user-customer', $i),
                'name' => $this->fakeName($arabic),
                'email' => $i % 2 === 0 ? "customer{$i}@bulk.example.test" : null,
                'phone' => $this->phone(self::CUSTOMER_PHONE_PREFIX, $i),
                'email_verified_at' => null,
                'phone_verified_at' => $this->ts($created),
                'password' => null,
                'user_type' => 'customer',
                'default_account' => null,
                'status' => $status,
                'remember_token' => null,
                'deleted_at' => $deleted,
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($created),
            ];
        }
    }

    private function providers(int $count): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            $created = $this->daysAgo(40, 700);
            // Likewise one suspended and one deleted provider, so their activities are always there to check.
            [$status, $deleted] = match (true) {
                $i === 3 && $count > 8 => ['suspended', null],
                $i === 7 && $count > 8 => ['active', $this->ts($this->daysAgo(1, 200))],
                default => $this->standing(5, 10),
            };

            yield [
                'uuid' => $this->uuid('user-provider', $i),
                'name' => $this->fakeName($this->chance(25)),
                'email' => $this->chance(30) ? "provider{$i}@bulk.example.test" : null,
                'phone' => $this->phone(self::PROVIDER_PHONE_PREFIX, $i),
                'email_verified_at' => null,
                'phone_verified_at' => $this->ts($created),
                'password' => null,
                'user_type' => 'provider',
                'default_account' => null,
                'status' => $status,
                'remember_token' => null,
                'deleted_at' => $deleted,
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($created),
            ];
        }
    }

    private function staff(int $count): \Generator
    {
        // Hashed once: bcrypt is slow by design, and every staff member shares the demo password.
        $password = Hash::make('password');

        for ($i = 0; $i < $count; $i++) {
            $created = $this->daysAgo(60, 700);

            yield [
                'uuid' => $this->uuid('user-staff', $i),
                'name' => $this->fakeName(),
                'email' => "staff{$i}@bulk.example.test",
                'phone' => $this->phone(self::STAFF_PHONE_PREFIX, $i),
                'email_verified_at' => $this->ts($created),
                'phone_verified_at' => null,
                'password' => $password,
                'user_type' => 'admin',
                'default_account' => null,
                'status' => 'active',
                'remember_token' => null,
                'deleted_at' => null,
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($created),
            ];
        }
    }
}
