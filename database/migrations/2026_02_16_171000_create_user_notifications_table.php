<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('title', 180);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sent_at', 'scheduled_for']);
            $table->index(['user_id', 'read_at']);
            $table->index(['household_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

