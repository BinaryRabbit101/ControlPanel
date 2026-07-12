<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_id');
            $table->string('category');
            $table->string('arg')->nullable();
            $table->string('status')->default('pending'); // pending | running | success | failed
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('action_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
