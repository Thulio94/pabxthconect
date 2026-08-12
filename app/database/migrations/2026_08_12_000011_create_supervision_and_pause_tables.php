<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pause_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 7)->default('#f4b000');
            $table->unsignedSmallInteger('max_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('extension_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('pause_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 24)->default('offline');
            $table->timestamp('state_since')->nullable();
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('supervision_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervisor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_extension_id')->constrained('extensions')->cascadeOnDelete();
            $table->foreignId('call_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 16);
            $table->string('status', 16)->default('started');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_sessions');
        Schema::dropIfExists('extension_presences');
        Schema::dropIfExists('pause_reasons');
    }
};
