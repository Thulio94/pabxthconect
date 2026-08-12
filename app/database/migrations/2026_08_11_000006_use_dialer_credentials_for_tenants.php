<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('internal_token')->nullable()->after('status');
            $table->dropColumn(['sip_server', 'sip_proxy', 'sip_domain', 'sip_websocket_url', 'ice_servers']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('sip_server')->nullable();
            $table->string('sip_proxy')->nullable();
            $table->string('sip_domain')->nullable();
            $table->string('sip_websocket_url')->nullable();
            $table->jsonb('ice_servers')->nullable();
            $table->dropColumn('internal_token');
        });
    }
};
