<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HubPageTest extends TestCase
{
    use RefreshDatabase;

    private string $mapPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapPath = tempnam(sys_get_temp_dir(), 'hubmap');
        config(['control_panel.hub.map_path' => $this->mapPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->mapPath);

        parent::tearDown();
    }

    private function useFixture(): void
    {
        copy(base_path('tests/Fixtures/hub-map.json'), $this->mapPath);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hub')->assertRedirect('/login');
    }

    public function test_the_map_renders_families_cards_and_connections(): void
    {
        $this->useFixture();

        $response = $this->actingAs(User::factory()->create())->get('/hub')->assertOk();

        $response
            ->assertSee('1 service')
            ->assertSee('1 idea')
            ->assertSee('4 projects')
            // Families in order, with blurbs.
            ->assertSeeInOrder(['Garden Tools', 'Everything for the pretend garden.', 'Pretend Kitchen', 'Fake recipes and fake ovens.'])
            // Inside a family: building, shipped, dormant; then services; ideas last.
            ->assertSeeInOrder(['Seedling', 'Trowel', 'Compost Heap', 'Sprinkler API', 'Greenhouse Idea', 'Toy Oven'])
            ->assertSee('Tracks imaginary seed trays.')
            ->assertSee('Next:')
            ->assertSee('Add a watering reminder.')
            ->assertSee('https://github.com/example-owner/seedling', false)
            ->assertSee('https://seedling.example.test/a/really/long/url/that/must/wrap/on/a/phone/screen', false)
            ->assertSee('example-box :9001')
            ->assertSee('Last active 2026-01-01')
            ->assertSee('GitHub')
            ->assertSee('No backup')
            ->assertSee('id="item-seedling"', false)
            // Connections both directions, resolved to names, linked in-page.
            ->assertSeeInOrder(['→', '<a href="#item-trowel"', 'Trowel', '— borrows its plot list'], false)
            ->assertSeeInOrder(['id="item-trowel"', '←', '<a href="#item-seedling"', 'Seedling', '— borrows its plot list'], false)
            // A used_by recorded only on the target still shows on both cards.
            ->assertSeeInOrder(['id="item-compost"', '←', '<a href="#item-sprinkler"', '— feeds it scraps'], false)
            ->assertSeeInOrder(['id="item-sprinkler"', '→', '<a href="#item-compost"', '— feeds it scraps'], false)
            // also_in: line on the card + a one-line stub in the other family.
            ->assertSee('Also in: Pretend Kitchen')
            ->assertSeeInOrder(['Pretend Kitchen', 'Toy Oven', 'Seedling', '— lives in Garden Tools'])
            // Kind badge only when not a project.
            ->assertSee('service')
            // Short notes shown with line breaks; long notes left off.
            ->assertSee('Short note line one.<br />', false)
            ->assertDontSee('LONGNOTEMARKER');

        // The Seedling card appears once — the kitchen gets a stub, not a duplicate card.
        $this->assertSame(1, substr_count($response->getContent(), 'id="item-seedling"'));
    }

    public function test_session_button_only_for_known_project_keys(): void
    {
        $this->useFixture();

        $this->actingAs(User::factory()->create())
            ->get('/hub')
            ->assertOk()
            ->assertSee('dusk="start-controlpanel"', false)
            ->assertDontSee('dusk="start-not-a-real-key"', false)
            ->assertSee('win.launch-claude')
            ->assertSee('Start Claude session');
    }

    public function test_a_missing_map_shows_the_empty_state(): void
    {
        @unlink($this->mapPath);

        $this->actingAs(User::factory()->create())
            ->get('/hub')
            ->assertOk()
            ->assertSee("The HUB map hasn't been synced yet", false)
            ->assertSee('build-map.py');
    }

    public function test_an_invalid_map_shows_the_empty_state(): void
    {
        file_put_contents($this->mapPath, '{not json');

        $this->actingAs(User::factory()->create())
            ->get('/hub')
            ->assertOk()
            ->assertSee("The HUB map hasn't been synced yet", false);
    }

    public function test_the_nav_links_to_the_hub(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('hub').'"', false);
    }
}
