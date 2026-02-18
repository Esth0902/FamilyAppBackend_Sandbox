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
        Schema::table('household_settings', function (Blueprint $table) {
            $table->json('tasks_config')->nullable()->after('has_calendar');
            $table->json('calendar_config')->nullable()->after('tasks_config');
            $table->json('budget_config')->nullable()->after('calendar_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('household_settings', function (Blueprint $table) {
            $table->dropColumn(['tasks_config', 'calendar_config', 'budget_config']);
        });
    }
};
