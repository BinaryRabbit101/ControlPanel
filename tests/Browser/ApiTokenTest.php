<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Profile → "API token": the Generate / Rotate / Revoke flow, in a real browser,
 * including the "shown once" rule and the Alpine copy button.
 *
 * Buttons are addressed by `dusk=` selectors, not label: the estate's button
 * components render `uppercase`, so the text a browser reports is "GENERATE".
 */
class ApiTokenTest extends DuskTestCase
{
    public function test_generate_shows_the_token_once_then_offers_rotate_and_revoke(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/profile')
                ->assertSee('API token')
                ->assertPresent('@api-token-generate')
                ->assertMissing('@api-token-revoke')
                ->click('@api-token-generate')
                ->waitFor('#api-token-plain');

            $plain = trim($browser->text('#api-token-plain'));
            $this->assertStringStartsWith('cp_', $plain);
            $this->assertSame(hash('sha256', $plain), $user->fresh()->api_token_hash);

            // Copy is wired through Alpine → clipboard; the "Copied." confirmation proves the click ran.
            $browser->click('@api-token-copy')
                ->waitForText('Copied.');

            // Shown once: a reload drops the plaintext and flips the buttons.
            $browser->refresh()
                ->assertDontSee($plain)
                ->assertMissing('#api-token-plain')
                ->assertPresent('@api-token-rotate')
                ->assertPresent('@api-token-revoke')
                ->assertMissing('@api-token-generate')
                ->assertSeeIn('@api-token', 'Active since');
        });
    }

    public function test_rotate_replaces_the_token_and_revoke_clears_it(): void
    {
        $user = User::factory()->create();
        $original = $user->mintApiToken();

        $this->browse(function (Browser $browser) use ($user, $original) {
            $browser->loginAs($user)
                ->visit('/profile')
                ->click('@api-token-rotate')
                ->acceptDialog()
                ->waitFor('#api-token-plain');

            $rotated = trim($browser->text('#api-token-plain'));
            $this->assertNotSame($original, $rotated);
            $this->assertSame(hash('sha256', $rotated), $user->fresh()->api_token_hash);

            $browser->click('@api-token-revoke')
                ->acceptDialog()
                ->waitForText('Revoked.')
                ->assertPresent('@api-token-generate')
                ->assertMissing('@api-token-rotate');

            $this->assertFalse($user->fresh()->hasApiToken());
        });
    }

    public function test_rotate_can_be_cancelled(): void
    {
        $user = User::factory()->create();
        $original = $user->mintApiToken();

        $this->browse(function (Browser $browser) use ($user, $original) {
            $browser->loginAs($user)
                ->visit('/profile')
                ->click('@api-token-rotate')
                ->dismissDialog()
                ->pause(300)
                ->assertMissing('#api-token-plain');

            $this->assertSame(hash('sha256', $original), $user->fresh()->api_token_hash);
        });
    }
}
