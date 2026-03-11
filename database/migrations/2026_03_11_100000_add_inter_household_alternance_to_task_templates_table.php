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
        Schema::table('task_templates', function (Blueprint $table) {
            $table->boolean('is_inter_household_alternating')
                ->default(false)
                ->after('rotation_cycle_weeks');
            $table->date('inter_household_week_start')
                ->nullable()
                ->after('is_inter_household_alternating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(['is_inter_household_alternating', 'inter_household_week_start']);
        });
    }
};
