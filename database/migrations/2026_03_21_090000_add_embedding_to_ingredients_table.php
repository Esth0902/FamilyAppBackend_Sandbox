<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ingredients', 'embedding')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->vector('embedding', dimensions: 512)
                    ->nullable()
                    ->after('category');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS ingredients_embedding_hnsw
                 ON ingredients
                 USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ingredients_embedding_hnsw');
        }

        if (Schema::hasColumn('ingredients', 'embedding')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->dropColumn('embedding');
            });
        }
    }
};
