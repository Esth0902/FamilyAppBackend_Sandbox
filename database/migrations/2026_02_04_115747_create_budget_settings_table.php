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
        Schema::create('budget_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('base_amount', 8, 2)->default(0);
            $table->enum('recurrence', ['weekly', 'monthly']) ->default('weekly');
            $table->integer('reset_day')->default(1);
            $table->boolean('allow_advances')->default(false);
            $table->decimal('max_advance_amount',8,2)->default(0);

            $table->timestamps();
            $table->unique(['household_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_settings');
    }
};
