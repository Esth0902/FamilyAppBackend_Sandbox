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
        Schema::table('task_templates', function (Blueprint $table): void {
            $table->date('end_date')
                ->nullable()
                ->after('start_date');
            $table->json('assignee_user_ids')
                ->nullable()
                ->after('recurrence_days');
            $table->json('rotation_user_ids')
                ->nullable()
                ->after('assignee_user_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table): void {
            $table->dropColumn([
                'end_date',
                'assignee_user_ids',
                'rotation_user_ids',
            ]);
        });
    }
};
