<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\HasUuid;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasUuid, SoftDeletes, HasRoles;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone',
        'avatar_path',
        'password',
        'user_type',
        'default_account',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function provider()
    {
        return $this->hasOne(Provider::class);
    }

    public function customer()
    {
        return $this->hasOne(Customer::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function otps()
    {
        return $this->hasMany(Otp::class);
    }

    public function providerRequests()
    {
        return $this->hasMany(ProviderRequest::class);
    }

    /**
     * user_type is the account a person REGISTERED as (customer, provider or admin). It does not say
     * which accounts they can use now: one phone can hold both a customer and a provider account
     * (see AccountService). Use AccountService for that, not these helpers.
     */
    public function isAdmin(): bool
    {
        return $this->user_type === 'admin';
    }

    public function isProvider(): bool
    {
        return $this->user_type === 'provider';
    }

    public function isCustomer(): bool
    {
        return $this->user_type === 'customer';
    }
}
