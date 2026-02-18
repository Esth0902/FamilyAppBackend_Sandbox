<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meal_settings', function (Blueprint $table) {
            $table->string('preferences', 1000)
                ->nullable()
                ->after('default_servings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_settings', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};

