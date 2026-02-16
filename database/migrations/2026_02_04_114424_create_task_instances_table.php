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
        Schema::create('task_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->constrained('task_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->date('due_date');
            $table->enum('status', ['à faire', 'réalisée', 'annulée'])->default('à faire');
            $table->timestamp('completed_at')->nullable();
            $table->boolean('validated_by_parent')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_instances');
    }
};
