<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HUB map lives on its own site now; ControlPanel only links to it.
 */
class HubLinkTest extends TestCase
{
    use RefreshDatabase;

    private const LAN = 'http://192.168.0.164:120/';

    private const TAILNET = 'https://minipc.jackal-hippocampus.ts.net:471/';

    public function test_old_hub_bookmarks_redirect_to_the_lan_site(): void
    {
        $this->get('/hub')->assertRedirect(self::LAN);
    }

    public function test_tailnet_visitors_are_sent_to_the_tailnet_address(): void
    {
        $this->get('https://minipc.jackal-hippocampus.ts.net:448/hub')->assertRedirect(self::TAILNET);
    }

    public function test_the_addresses_come_from_config(): void
    {
        config(['control_panel.hub_url.lan' => 'http://hub.example.test/']);

        $this->get('/hub')->assertRedirect('http://hub.example.test/');
    }

    public function test_the_hub_redirect_is_lan_only(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/hub')->assertForbidden();
    }

    public function test_the_nav_links_to_the_hub_site_in_the_same_tab(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'href="'.self::LAN.'"'), 'desktop + responsive HUB links');
        $this->assertDoesNotMatchRegularExpression('#href="'.preg_quote(self::LAN, '#').'"[^>]*target=#', $html);
        $this->assertStringNotContainsString(url('/hub'), $html);
    }

    public function test_the_nav_uses_the_tailnet_address_over_the_tailnet(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://minipc.jackal-hippocampus.ts.net:448/dashboard')
            ->assertOk()
            ->assertSee('href="'.self::TAILNET.'"', false)
            ->assertDontSee('href="'.self::LAN.'"', false);
    }
}
