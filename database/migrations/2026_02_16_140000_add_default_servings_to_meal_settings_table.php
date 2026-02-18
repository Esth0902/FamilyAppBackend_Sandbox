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
            $table->unsignedSmallInteger('default_servings')
                ->default(4)
                ->after('max_votes_per_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_settings', function (Blueprint $table) {
            $table->dropColumn('default_servings');
        });
    }
};

