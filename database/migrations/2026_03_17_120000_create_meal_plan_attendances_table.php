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
        Schema::create('meal_plan_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained('meal_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['present', 'not_home', 'later']);
            $table->string('reason', 255)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'user_id']);
            $table->index(['household_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_plan_attendances');
    }
};
