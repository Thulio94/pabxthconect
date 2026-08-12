<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('recording_retention_days')->default(90)->after('record_calls');
            $table->unsignedSmallInteger('extension_min')->default(999)->after('recording_retention_days');
            $table->unsignedSmallInteger('extension_max')->default(10000)->after('extension_min');
        });

        Schema::create('pbx_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('host');
            $table->string('ami_host')->nullable();
            $table->unsignedSmallInteger('ami_port')->default(5038);
            $table->text('ami_username')->nullable();
            $table->text('ami_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('auth_mode', 20); // ip_tech or userpass
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(5060);
            $table->string('transport', 10)->default('udp');
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->string('tech_prefix')->nullable();
            $table->string('outbound_proxy')->nullable();
            $table->string('from_domain')->nullable();
            $table->string('from_user')->nullable();
            $table->json('codecs')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_sip_trunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sip_trunk_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sip_trunk_id']);
        });

        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pbx_node_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('sip_username')->unique();
            $table->text('sip_secret');
            $table->string('status', 20)->default('active');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('call_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sip_trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asterisk_uniqueid')->nullable()->index();
            $table->string('asterisk_linkedid')->nullable()->index();
            $table->string('direction', 12)->default('outbound');
            $table->string('from_number')->nullable();
            $table->string('to_number');
            $table->string('dialed_uri')->nullable();
            $table->string('status', 32)->default('initiated');
            $table->string('hangup_cause', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamps();
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('storage_disk')->default('local');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delete_after')->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
        Schema::dropIfExists('call_records');
        Schema::dropIfExists('extensions');
        Schema::dropIfExists('tenant_sip_trunks');
        Schema::dropIfExists('sip_trunks');
        Schema::dropIfExists('pbx_nodes');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['recording_retention_days', 'extension_min', 'extension_max']);
        });
    }
};
