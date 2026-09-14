<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // sha256 of the plaintext token; the plaintext is shown once and never stored.
            $table->string('api_token_hash', 64)->nullable()->unique()->after('remember_token');
            $table->timestamp('api_token_created_at')->nullable()->after('api_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SQLite refuses to drop a column that still carries an index.
            $table->dropUnique(['api_token_hash']);
            $table->dropColumn(['api_token_hash', 'api_token_created_at']);
        });
    }
};
