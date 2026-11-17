<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The box still has the Passport tables from the old in-app connector; the
 * drop migration must clear them there and be harmless on a fresh database.
 */
class DropOauthTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients', 'oauth_device_codes'];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_17_000001_drop_oauth_tables.php');
    }

    public function test_a_fresh_database_has_no_oauth_tables(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->migration()->up();
    }

    public function test_it_drops_the_oauth_tables_a_migrated_box_still_has(): void
    {
        foreach (self::TABLES as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->string('id')->primary());
        }

        $this->migration()->up();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }
}
