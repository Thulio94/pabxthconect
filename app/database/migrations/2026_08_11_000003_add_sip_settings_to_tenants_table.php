<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('sip_server')->nullable()->after('status');
            $table->string('sip_proxy')->nullable()->after('sip_server');
            $table->string('sip_domain')->nullable()->after('sip_proxy');
            $table->string('sip_websocket_url')->nullable()->after('sip_domain');
            $table->jsonb('ice_servers')->nullable()->after('sip_websocket_url');
        });

        DB::table('tenants')->update([
            'sip_websocket_url' => 'wss://ws.discador.thconect.com.br',
        ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['sip_server', 'sip_proxy', 'sip_domain', 'sip_websocket_url', 'ice_servers']);
        });
    }
};
