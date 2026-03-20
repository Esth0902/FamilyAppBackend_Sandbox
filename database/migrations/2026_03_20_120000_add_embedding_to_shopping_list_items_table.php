<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shopping_list_items', 'embedding')) {
            Schema::table('shopping_list_items', function (Blueprint $table): void {
                $table->vector('embedding', dimensions: 512)
                    ->nullable()
                    ->after('unit');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS shopping_list_items_embedding_hnsw
                 ON shopping_list_items
                 USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS shopping_list_items_embedding_hnsw');
        }

        if (Schema::hasColumn('shopping_list_items', 'embedding')) {
            Schema::table('shopping_list_items', function (Blueprint $table): void {
                $table->dropColumn('embedding');
            });
        }
    }
};
