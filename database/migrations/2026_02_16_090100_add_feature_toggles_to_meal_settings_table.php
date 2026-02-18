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
            $table->boolean('enable_recipes')->default(true)->after('poll_duration');
            $table->boolean('enable_polls')->default(true)->after('enable_recipes');
            $table->boolean('enable_shopping_list')->default(true)->after('enable_polls');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_settings', function (Blueprint $table) {
            $table->dropColumn(['enable_recipes', 'enable_polls', 'enable_shopping_list']);
        });
    }
};
