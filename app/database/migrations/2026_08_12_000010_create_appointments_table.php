<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 11);
            $table->timestamp('scheduled_for');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('snooze_count')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'scheduled_for']);
            $table->index(['tenant_id', 'extension_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
