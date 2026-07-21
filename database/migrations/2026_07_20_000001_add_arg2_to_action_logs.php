<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            // Optional secondary argument (e.g. the model chosen for a launch).
            $table->string('arg2')->nullable()->after('arg');
        });
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->dropColumn('arg2');
        });
    }
};
