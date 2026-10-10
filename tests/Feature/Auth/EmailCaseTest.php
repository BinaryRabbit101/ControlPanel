<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phones capitalise the first letter of an email. Sign-in and password reset match the
 * address in any case. (Registration is disabled here: see routes/auth.php.)
 */
class EmailCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_matches_a_lowercase_account_in_any_case(): void
    {
        $user = User::factory()->create(['email' => 'emily@example.com']);

        $this->post('/login', ['email' => ' Emily@Example.com ', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_matches_an_account_stored_in_mixed_case(): void
    {
        $user = User::factory()->create(['email' => 'Emily@Example.com']);

        $this->post('/login', ['email' => 'emily@example.COM', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_still_refuses_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'emily@example.com']);

        $this->post('/login', ['email' => 'Emily@Example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_password_reset_works_in_any_case(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'Emily@Example.com']);

        $this->post('/forgot-password', ['email' => 'EMILY@example.com'])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => 'emily@EXAMPLE.com',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertSessionHasNoErrors();

            return Hash::check('new-password-123', $user->fresh()->password);
        });
    }
}
