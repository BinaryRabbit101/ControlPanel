<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No tunnel should reach ControlPanel any more; anything that arrives with
 * Cloudflare's headers is refused on every LAN page, whatever it claims.
 */
class CloudflareBackstopTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloudflare_requests_are_refused_on_lan_pages(): void
    {
        $user = User::factory()->create();

        foreach (['Cf-Ray' => '8f00000000000000-DFW', 'Cf-Connecting-Ip' => '192.168.0.50'] as $header => $value) {
            $this->actingAs($user)
                ->withHeader($header, $value)
                ->get('/dashboard')
                ->assertForbidden();
        }
    }

    public function test_the_old_connector_endpoints_are_gone(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
        $this->postJson('/oauth/register', [])->assertNotFound();
        $this->get('/oauth/authorize')->assertNotFound();
    }
}
