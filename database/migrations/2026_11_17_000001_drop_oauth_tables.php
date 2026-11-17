<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The Claude connector (Passport OAuth) moved out of ControlPanel into Butler.
 * Drop the tables its Passport migrations made on the box; on a fresh database
 * they never existed and this is a no-op. Nothing to restore on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_device_codes', 'oauth_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        //
    }
};
