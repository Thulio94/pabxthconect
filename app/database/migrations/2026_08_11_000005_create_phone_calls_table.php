<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('record_calls')->default(true)->after('ice_servers');
        });

        Schema::create('phone_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('extension', 80);
            $table->string('direction', 12);
            $table->string('remote_number', 80)->nullable();
            $table->string('status', 40)->default('initiated');
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('recording_path')->nullable();
            $table->string('recording_mime', 100)->nullable();
            $table->unsignedBigInteger('recording_size')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'extension', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_calls');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('record_calls');
        });
    }
};
