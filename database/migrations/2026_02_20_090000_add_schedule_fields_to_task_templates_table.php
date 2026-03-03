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
            $table->json('recurrence_days')->nullable()->after('recurrence');
            $table->unsignedTinyInteger('rotation_cycle_weeks')->default(1)->after('is_rotation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(['recurrence_days', 'rotation_cycle_weeks']);
        });
    }
};
