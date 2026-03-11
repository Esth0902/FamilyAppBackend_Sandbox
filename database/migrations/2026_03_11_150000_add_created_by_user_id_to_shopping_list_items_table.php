<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shopping_list_items', 'created_by_user_id')) {
            Schema::table('shopping_list_items', function (Blueprint $table): void {
                $table->foreignId('created_by_user_id')
                    ->nullable()
                    ->after('is_manual_addition')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopping_list_items', 'created_by_user_id')) {
            Schema::table('shopping_list_items', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('created_by_user_id');
            });
        }
    }
};

