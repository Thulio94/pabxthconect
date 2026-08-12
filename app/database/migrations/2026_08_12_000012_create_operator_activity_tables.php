<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 120)->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('logged_in_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('logged_out_at')->nullable()->index();
            $table->timestamps();
            $table->index(['tenant_id', 'extension_id', 'logged_in_at']);
        });

        Schema::create('operator_pause_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pause_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pause_name', 80);
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
            $table->index(['tenant_id', 'extension_id', 'started_at']);
        });

        Schema::create('operator_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40)->index();
            $table->string('description', 255);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['tenant_id', 'extension_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_activity_logs');
        Schema::dropIfExists('operator_pause_sessions');
        Schema::dropIfExists('operator_sessions');
    }
};
