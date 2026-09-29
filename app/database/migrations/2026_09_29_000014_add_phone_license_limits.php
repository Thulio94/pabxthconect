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
            $table->unsignedSmallInteger('concurrent_agent_limit')->default(1)->after('extension_max');
        });

        DB::table('tenants')->orderBy('id')->get()->each(function (object $tenant): void {
            $agents = DB::table('users')->where('tenant_id', $tenant->id)->where('role', 'agent')->count();
            DB::table('tenants')->where('id', $tenant->id)->update(['concurrent_agent_limit' => $agents]);
        });

        Schema::create('phone_license_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 120)->nullable();
            $table->timestamps();
            $table->unique('extension_id');
            $table->index(['tenant_id', 'created_at']);
        });

        DB::table('operator_sessions')
            ->join('users', 'users.id', '=', 'operator_sessions.user_id')
            ->whereNull('operator_sessions.logged_out_at')
            ->where('users.role', 'agent')
            ->orderBy('operator_sessions.id')
            ->select('operator_sessions.tenant_id', 'operator_sessions.user_id', 'operator_sessions.extension_id', 'operator_sessions.session_key', 'operator_sessions.created_at', 'operator_sessions.updated_at')
            ->get()
            ->unique('extension_id')
            ->each(fn (object $session) => DB::table('phone_license_leases')->insert([
                'tenant_id' => $session->tenant_id,
                'user_id' => $session->user_id,
                'extension_id' => $session->extension_id,
                'session_key' => $session->session_key,
                'created_at' => $session->created_at ?? now(),
                'updated_at' => $session->updated_at ?? now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_license_leases');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('concurrent_agent_limit');
        });
    }
};
