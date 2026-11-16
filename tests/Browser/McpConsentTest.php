<?php

namespace Tests\Browser;

use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Laravel\Dusk\Browser;
use Laravel\Passport\ClientRepository;
use Tests\DuskTestCase;

/**
 * The Household connector's consent screen (Passport's authorize page,
 * restyled): signed in, Claude's authorize link shows a plain "Claude wants
 * to use ControlPanel for you" with Approve and Cancel, fits a phone with no
 * sideways scroll, and Approve hands the browser back to Claude with a code.
 */
class McpConsentTest extends DuskTestCase
{
    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    private function authorizePath(string $clientId): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '=');

        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'scope' => 'mcp:use',
            'state' => 'dusk',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function test_the_consent_screen_shows_approve_and_fits_a_phone(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'Claude', redirectUris: [self::CALLBACK], confidential: false,
        );

        $this->browse(function (Browser $browser) use ($user, $client) {
            $devtools = new ChromeDevToolsDriver($browser->driver);
            $devtools->execute('Emulation.setDeviceMetricsOverride', [
                'width' => 390, 'height' => 844, 'deviceScaleFactor' => 1, 'mobile' => true,
            ]);

            try {
                $browser->loginAs($user)
                    ->visit($this->authorizePath((string) $client->id))
                    ->waitFor('@approve')
                    ->assertSee('Claude wants to use ControlPanel for you')
                    ->assertSee('owner@example.com')
                    ->assertVisible('@approve')
                    ->assertVisible('@cancel')
                    ->screenshot('mcp-consent-phone');

                $overflow = $browser->script('return document.documentElement.scrollWidth - document.documentElement.clientWidth;')[0];
                $this->assertLessThanOrEqual(0, $overflow, 'The consent screen scrolls sideways on a phone.');

                // Approve: Passport redirects to Claude's callback with a code.
                // Block claude.ai so the test browser never actually goes there.
                $devtools->execute('Network.enable', []);
                $devtools->execute('Network.setBlockedURLs', ['urls' => ['*claude.ai*']]);
                $browser->click('@approve')->pause(1500);

                $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $client->id, 'user_id' => $user->id, 'revoked' => false]);
            } finally {
                $devtools->execute('Network.setBlockedURLs', ['urls' => []]);
                $devtools->execute('Emulation.clearDeviceMetricsOverride', []);
            }
        });
    }
}
