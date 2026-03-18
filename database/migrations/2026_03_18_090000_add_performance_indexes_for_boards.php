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
            $table->index(['household_id', 'id'], 'task_templates_household_id_id_idx');
        });

        Schema::table('task_instances', function (Blueprint $table) {
            $table->index(['task_template_id', 'due_date', 'id'], 'task_instances_template_due_id_idx');
            $table->index(['due_date', 'task_template_id'], 'task_instances_due_template_idx');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index(['household_id', 'start_at', 'end_at'], 'events_household_start_end_idx');
            $table->index(
                ['household_id', 'is_shared_with_other_household', 'start_at', 'end_at'],
                'events_household_shared_dates_idx'
            );
        });

        Schema::table('pocket_money_transactions', function (Blueprint $table) {
            $table->index(
                ['household_id', 'user_id', 'created_at'],
                'pocket_money_household_user_created_idx'
            );
            $table->index(
                ['household_id', 'type', 'status', 'created_at'],
                'pocket_money_household_type_status_idx'
            );
        });

        Schema::table('meal_polls', function (Blueprint $table) {
            $table->index(['household_id', 'starts_at'], 'meal_polls_household_starts_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_polls', function (Blueprint $table) {
            $table->dropIndex('meal_polls_household_starts_idx');
        });

        Schema::table('pocket_money_transactions', function (Blueprint $table) {
            $table->dropIndex('pocket_money_household_type_status_idx');
            $table->dropIndex('pocket_money_household_user_created_idx');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_household_shared_dates_idx');
            $table->dropIndex('events_household_start_end_idx');
        });

        Schema::table('task_instances', function (Blueprint $table) {
            $table->dropIndex('task_instances_due_template_idx');
            $table->dropIndex('task_instances_template_due_id_idx');
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropIndex('task_templates_household_id_id_idx');
        });
    }
};

