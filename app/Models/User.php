<?php

namespace App\Models;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements WalletInterface
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasWallet, HasWallets, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
        'last_activity_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Role helpers
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager' || $this->isAdmin();
    }

    public function isAgent(): bool
    {
        return $this->role === 'agent' || $this->isManager();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function getOrCreateCurrencyWallet(string $currency)
    {
        $slug = strtoupper($currency);

        return $this->getWallet($slug) ?? $this->createWallet([
            'name' => $slug.' Wallet',
            'slug' => $slug,
            'meta' => ['currency' => $slug],
        ]);
    }
}
