<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_polls', function (Blueprint $table): void {
            $table->date('planning_start_date')->nullable()->after('ends_at');
            $table->date('planning_end_date')->nullable()->after('planning_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('meal_polls', function (Blueprint $table): void {
            $table->dropColumn(['planning_start_date', 'planning_end_date']);
        });
    }
};

