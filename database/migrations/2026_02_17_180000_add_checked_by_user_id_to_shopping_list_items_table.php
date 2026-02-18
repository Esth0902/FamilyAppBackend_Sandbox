<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shopping_list_items', 'checked_by_user_id')) {
            Schema::table('shopping_list_items', function (Blueprint $table): void {
                $table->foreignId('checked_by_user_id')
                    ->nullable()
                    ->after('is_checked')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopping_list_items', 'checked_by_user_id')) {
            Schema::table('shopping_list_items', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('checked_by_user_id');
            });
        }
    }
};
