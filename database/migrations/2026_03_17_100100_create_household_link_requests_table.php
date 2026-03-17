<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_link_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('to_household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('household_link_code_id')->nullable()->constrained('household_link_codes')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['from_household_id', 'status']);
            $table->index(['to_household_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_link_requests');
    }
};
