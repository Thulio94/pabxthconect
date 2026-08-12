<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropUnique('extensions_tenant_id_number_unique');
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropUnique('extensions_number_unique');
            $table->unique(['tenant_id', 'number']);
        });
    }
};
