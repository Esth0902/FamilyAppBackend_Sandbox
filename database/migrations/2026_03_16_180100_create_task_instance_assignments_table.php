<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_instance_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_instance_id')->constrained('task_instances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_instance_id', 'user_id'], 'task_instance_assignments_unique');
        });

        DB::table('task_instances')
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(500, function ($instances): void {
                $rows = [];
                $now = now();

                foreach ($instances as $instance) {
                    $instanceId = (int) ($instance->id ?? 0);
                    $userId = (int) ($instance->user_id ?? 0);
                    if ($instanceId <= 0 || $userId <= 0) {
                        continue;
                    }

                    $rows[] = [
                        'task_instance_id' => $instanceId,
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (count($rows) > 0) {
                    DB::table('task_instance_assignments')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_instance_assignments');
    }
};
