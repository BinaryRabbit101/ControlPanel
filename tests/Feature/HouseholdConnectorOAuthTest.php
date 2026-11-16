<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The Household connector's public side: what Claude sees through the
 * Cloudflare Tunnel at https://mcp.thecorgisquad.com. The tunnel talks plain
 * http to nginx and says where the request really came from in
 * X-Forwarded-Proto / X-Forwarded-Host, so every URL Claude is handed must
 * come out https on the public host — while the LAN dashboard keeps its own
 * address and the dashboard itself stays LAN-only.
 */
class HouseholdConnectorOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'mcp.thecorgisquad.com';

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Passport signs tokens with storage/oauth-*.key (gitignored; the box
        // has its own). Make a local pair the first time the suite runs.
        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }
    }

    /** @return array<string, string> */
    private function tunnel(): array
    {
        return [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => self::HOST,
            'X-Forwarded-For' => '203.0.113.7',
        ];
    }

    private function registerClient(): string
    {
        return $this->withHeaders($this->tunnel())
            ->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])
            ->assertCreated()
            ->json('client_id');
    }

    /** @return array{0: string, 1: string} the authorize URL and its PKCE verifier */
    private function authorizeUrl(string $clientId): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['https://'.self::HOST.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]), $verifier];
    }

    // ---- /mcp without a token -------------------------------------------

    public function test_an_unauthenticated_mcp_call_is_a_401_that_points_at_the_metadata(): void
    {
        $response = $this->withHeaders($this->tunnel())
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(401);

        $this->assertTrue($response->headers->has('WWW-Authenticate'));
        $this->assertStringContainsString(
            'resource_metadata="https://'.self::HOST.'/.well-known/oauth-protected-resource/mcp"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_an_unauthenticated_mcp_call_without_json_accept_is_still_a_401_not_a_login_redirect(): void
    {
        $this->withHeaders($this->tunnel())
            ->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');
    }

    // ---- discovery metadata -----------------------------------------------

    public function test_the_metadata_is_https_on_the_public_host_behind_the_tunnel(): void
    {
        $this->withHeaders($this->tunnel())
            ->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', 'https://'.self::HOST.'/mcp')
            ->assertJsonPath('authorization_servers.0', 'https://'.self::HOST);

        $this->withHeaders($this->tunnel())
            ->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://'.self::HOST)
            ->assertJsonPath('authorization_endpoint', 'https://'.self::HOST.'/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://'.self::HOST.'/oauth/token')
            ->assertJsonPath('registration_endpoint', 'https://'.self::HOST.'/oauth/register')
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_the_lan_address_still_gets_lan_urls(): void
    {
        $this->getJson('http://192.168.0.164:85/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'http://192.168.0.164:85')
            ->assertJsonPath('token_endpoint', 'http://192.168.0.164:85/oauth/token');
    }

    // ---- dynamic client registration ------------------------------------

    public function test_registration_accepts_a_claude_redirect(): void
    {
        $this->withHeaders($this->tunnel())
            ->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])
            ->assertCreated()
            ->assertJsonPath('redirect_uris', [self::CALLBACK])
            ->assertJsonPath('scope', 'mcp:use');

        $this->withHeaders($this->tunnel())
            ->postJson('/oauth/register', ['redirect_uris' => ['https://claude.com/api/mcp/auth_callback']])
            ->assertCreated();
    }

    public function test_registration_refuses_a_foreign_redirect(): void
    {
        foreach (['https://evil.example/callback', 'https://claude.ai.evil.example/cb', 'http://claude.ai/api/mcp/auth_callback'] as $uri) {
            $this->withHeaders($this->tunnel())
                ->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$uri]])
                ->assertStatus(400)
                ->assertJsonPath('error', 'invalid_redirect_uri');
        }

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($this->tunnel())
                ->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/cb']])
                ->assertStatus(400);
        }

        $this->withHeaders($this->tunnel())
            ->postJson('/oauth/register', ['redirect_uris' => [self::CALLBACK]])
            ->assertStatus(429);
    }

    // ---- sign-in on the public host ------------------------------------

    public function test_the_dashboard_profile_and_shortcut_api_stay_closed_on_the_public_host(): void
    {
        $user = User::factory()->create();
        $token = $user->mintApiToken();

        // Even when the forwarded IP looks local, the public host is not the LAN.
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => self::HOST, 'X-Forwarded-For' => '192.168.0.50'];

        $this->actingAs($user)->withHeaders($headers)->get('/dashboard')->assertForbidden();
        $this->actingAs($user)->withHeaders($headers)->get('/profile')->assertForbidden();
        $this->withHeaders($headers + ['X-Api-Token' => $token])->getJson('/api/shortcut/projects')->assertForbidden();

        // …and on the LAN they are untouched.
        $this->flushHeaders()->actingAs($user)->get('http://192.168.0.164:85/dashboard')->assertOk();
    }

    public function test_login_works_on_the_public_host_and_returns_to_the_authorize_page(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        [$url] = $this->authorizeUrl($this->registerClient());

        // No session yet: Passport sends the browser to sign in on the same host.
        $this->withHeaders($this->tunnel())
            ->get($url)
            ->assertRedirect('https://'.self::HOST.'/login');

        // Remembered on the public host, query intact, for redirect()->intended().
        $intended = (string) session('url.intended');
        $this->assertStringStartsWith('https://'.self::HOST.'/oauth/authorize?', $intended);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $sent);
        parse_str((string) parse_url($intended, PHP_URL_QUERY), $kept);
        $this->assertEqualsCanonicalizing($sent, $kept);

        $this->withHeaders($this->tunnel())->get('/login')->assertOk();

        $this->withHeaders($this->tunnel())
            ->withSession(['url.intended' => $intended])
            ->post('/login', ['email' => 'owner@example.com', 'password' => 'password'])
            ->assertRedirect($intended);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_consent_screen_speaks_plainly_and_approving_issues_a_working_token(): void
    {
        Process::fake(['*' => Process::result(output: 'Reply')]);
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        [$url, $verifier] = $this->authorizeUrl($clientId);

        $this->actingAs($user)
            ->withHeaders($this->tunnel())
            ->get($url)
            ->assertOk()
            ->assertSee('Claude wants to use ControlPanel for you')
            ->assertSee('dusk="approve"', false)
            ->assertSee('dusk="cancel"', false)
            ->assertSee('https://'.self::HOST.'/oauth/authorize', false)
            ->assertHeader('X-Frame-Options', 'DENY');

        $redirect = $this->actingAs($user)
            ->withHeaders($this->tunnel())
            ->post('/oauth/authorize', [
                'state' => '',
                'client_id' => $clientId,
                'auth_token' => session('authToken'),
            ])
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(self::CALLBACK.'?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        $token = $this->withHeaders($this->tunnel())
            ->postJson('/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'redirect_uri' => self::CALLBACK,
                'code_verifier' => $verifier,
                'code' => $query['code'],
            ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in'])
            ->json('access_token');

        // Forget the browser session: the bearer token alone must do.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->tunnel() + ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'pc_status', 'arguments' => ['pc' => 'gemini']]])
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'Gemini is awake.');

        // Attributed to the account that approved the connector.
        $this->assertDatabaseHas('action_logs', ['user_id' => $user->id, 'action_id' => 'win.ping']);
    }

    public function test_cancel_sends_claude_an_access_denied(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        [$url] = $this->authorizeUrl($clientId);

        $this->actingAs($user)->withHeaders($this->tunnel())->get($url)->assertOk();

        $location = $this->actingAs($user)
            ->withHeaders($this->tunnel())
            ->delete('/oauth/authorize', ['state' => '', 'client_id' => $clientId, 'auth_token' => session('authToken')])
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(self::CALLBACK, $location);
        $this->assertStringContainsString('error=access_denied', $location);
    }

    // ---- review fixes: spoofing, framing, throttles, cookies, landing pages ----

    public function test_a_spoofed_forwarded_host_through_cloudflare_is_still_public(): void
    {
        $user = User::factory()->create();

        // Through the tunnel (Cf-Ray), claiming the LAN host and a LAN IP.
        $this->actingAs($user)
            ->withHeaders(['Cf-Ray' => '8f00000000000000-DFW', 'X-Forwarded-Host' => '192.168.0.164', 'X-Forwarded-For' => '192.168.0.50'])
            ->get('http://127.0.0.1/dashboard')
            ->assertForbidden();

        $this->flushHeaders()->actingAs($user)
            ->withHeaders(['Cf-Connecting-Ip' => '203.0.113.7'])
            ->get('http://127.0.0.1/profile')
            ->assertForbidden();
    }

    public function test_the_raw_public_host_is_public_even_with_a_lan_forwarded_host_or_a_trailing_dot(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['X-Forwarded-Host' => '192.168.0.164'])
            ->get('http://'.self::HOST.'/dashboard')
            ->assertForbidden();

        $this->flushHeaders()->actingAs($user)->get('http://MCP.thecorgisquad.com./dashboard')->assertForbidden();
        $this->actingAs($user)->withHeaders(['X-Forwarded-Host' => self::HOST.'.'])->get('http://127.0.0.1/dashboard')->assertForbidden();
    }

    public function test_the_lan_and_tailscale_loopback_without_cloudflare_headers_still_pass(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('http://192.168.0.164:85/dashboard')->assertOk();

        // Tailscale Serve: https from loopback, its own host, no Cf-* headers.
        $this->actingAs($user)
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'minipc.jackal-hippocampus.ts.net:448'])
            ->get('http://127.0.0.1/dashboard')
            ->assertOk();
    }

    public function test_no_page_can_be_framed(): void
    {
        $user = User::factory()->create();
        [$url] = $this->authorizeUrl($this->registerClient());

        $responses = [
            $this->flushHeaders()->get('http://192.168.0.164:85/login'),
            $this->withHeaders($this->tunnel())->get('https://'.self::HOST.'/login'),
            $this->actingAs($user)->withHeaders($this->tunnel())->get($url),
            $this->flushHeaders()->actingAs($user)->get('http://192.168.0.164:85/dashboard'),
        ];

        foreach ($responses as $response) {
            $response->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString("frame-ancestors 'none'", implode(';', $response->headers->all('content-security-policy')));
        }
    }

    public function test_login_is_also_throttled_per_email_across_ips(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        for ($i = 1; $i <= 20; $i++) {
            $this->flushHeaders()->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"] + $this->tunnel())
                ->post('https://'.self::HOST.'/login', ['email' => 'Owner@Example.com', 'password' => 'wrong'])
                ->assertStatus(302);
        }

        $this->flushHeaders()->withHeaders(['X-Forwarded-For' => '198.51.100.9'] + $this->tunnel())
            ->post('https://'.self::HOST.'/login', ['email' => 'owner@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_token_less_mcp_floods_are_capped_per_ip_before_auth(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->withHeaders($this->tunnel())
                ->postJson('https://'.self::HOST.'/mcp', ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'tools/list'])
                ->assertStatus(401);
        }

        $this->withHeaders($this->tunnel())
            ->postJson('https://'.self::HOST.'/mcp', ['jsonrpc' => '2.0', 'id' => 'x', 'method' => 'tools/list'])
            ->assertStatus(429);
    }

    public function test_the_session_cookie_is_secure_on_https_only(): void
    {
        $cookie = fn ($response) => collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $public = $this->withHeaders($this->tunnel())->get('https://'.self::HOST.'/login')->assertOk();
        $this->assertTrue($cookie($public)?->isSecure());

        $lan = $this->flushHeaders()->get('http://192.168.0.164:85/login')->assertOk();
        $this->assertFalse($cookie($lan)?->isSecure());
    }

    public function test_signing_in_on_the_public_host_with_nowhere_to_return_lands_on_a_plain_page(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->withHeaders($this->tunnel())
            ->post('https://'.self::HOST.'/login', ['email' => 'owner@example.com', 'password' => 'password'])
            ->assertRedirect('https://'.self::HOST.'/login');

        $this->withHeaders($this->tunnel())
            ->get('https://'.self::HOST.'/login')
            ->assertOk()
            ->assertSee("You're signed in.", false)
            ->assertSee('Go back to Claude to finish.');

        // Signed in on the LAN, /login still goes to the dashboard.
        $this->flushHeaders()->get('http://192.168.0.164:85/login')->assertRedirect(route('dashboard'));
    }

    public function test_a_signed_in_login_post_on_the_public_host_is_not_sent_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->tunnel())
            ->post('https://'.self::HOST.'/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login');
    }

    public function test_signing_out_on_the_public_host_goes_back_to_the_sign_in_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders($this->tunnel())
            ->post('https://'.self::HOST.'/logout')
            ->assertRedirect('https://'.self::HOST.'/login');

        $this->assertGuest();
    }
}
