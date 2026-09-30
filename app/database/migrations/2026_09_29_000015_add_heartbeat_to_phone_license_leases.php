<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_license_leases', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->index();
        });

        DB::table('phone_license_leases')->whereNull('last_seen_at')->update(['last_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('phone_license_leases', function (Blueprint $table): void {
            $table->dropColumn('last_seen_at');
        });
    }
};
