<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // N'oublie pas d'importer DB pour l'update

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            // Ajoute la colonne (PostgreSQL gère très bien le type boolean natif)
            $table->boolean('is_setup_completed')->default(false);
        });

        DB::table('households')->update(['is_setup_completed' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('is_setup_completed');
        });
    }
};