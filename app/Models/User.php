<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'api_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'api_token_created_at' => 'datetime',
        ];
    }

    // ---- API token (Profile → "API token"; used by the Shortcut endpoints) ----
    // Same shape as the other estate sites' widget tokens: one per account,
    // Generate / Rotate / Revoke, plaintext shown once. Only the sha256 is
    // stored, so a leaked database does not leak working tokens.

    public function hasApiToken(): bool
    {
        return $this->api_token_hash !== null;
    }

    /** Mints (or rotates) the token and returns the plaintext — the only time it exists. */
    public function mintApiToken(): string
    {
        $plain = 'cp_'.Str::random(48);

        $this->forceFill([
            'api_token_hash' => self::hashApiToken($plain),
            'api_token_created_at' => now(),
        ])->save();

        return $plain;
    }

    public function revokeApiToken(): void
    {
        $this->forceFill(['api_token_hash' => null, 'api_token_created_at' => null])->save();
    }

    public static function findByApiToken(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()->where('api_token_hash', self::hashApiToken($plain))->first();
    }

    public static function hashApiToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
