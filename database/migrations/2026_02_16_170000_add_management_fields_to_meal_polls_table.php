<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_polls', function (Blueprint $table): void {
            $table->string('title', 150)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('max_votes_per_user')->default(3);
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('closing_soon_sent_at')->nullable();
            $table->timestamp('close_request_sent_at')->nullable();

            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('meal_polls', function (Blueprint $table): void {
            $table->dropIndex(['household_id', 'status']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('validated_by_user_id');
            $table->dropColumn([
                'title',
                'max_votes_per_user',
                'closed_at',
                'validated_at',
                'reminder_sent_at',
                'closing_soon_sent_at',
                'close_request_sent_at',
            ]);
        });
    }
};

