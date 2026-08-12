<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('call_sessions');
    }

    public function down(): void
    {
        // O histórico do webphone é mantido somente na sessão do navegador.
    }
};
