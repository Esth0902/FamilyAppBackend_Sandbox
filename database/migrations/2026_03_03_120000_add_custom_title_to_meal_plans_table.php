<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('meal_plans', 'custom_title')) {
            Schema::table('meal_plans', function (Blueprint $table): void {
                $table->string('custom_title', 120)->nullable()->after('meal_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('meal_plans', 'custom_title')) {
            Schema::table('meal_plans', function (Blueprint $table): void {
                $table->dropColumn('custom_title');
            });
        }
    }
};
