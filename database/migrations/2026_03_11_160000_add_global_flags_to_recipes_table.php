<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->boolean('is_global')->default(false)->after('household_id');
            $table->index('is_global');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipes ALTER COLUMN household_id DROP NOT NULL');
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE recipes MODIFY household_id BIGINT UNSIGNED NULL');
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite change() nécessite dbal sur certains environnements.
            Schema::table('recipes', function (Blueprint $table) {
                $table->unsignedBigInteger('household_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('recipes')->whereNull('household_id')->delete();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipes ALTER COLUMN household_id SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE recipes MODIFY household_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'sqlite') {
            Schema::table('recipes', function (Blueprint $table) {
                $table->unsignedBigInteger('household_id')->nullable(false)->change();
            });
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['is_global']);
            $table->dropColumn('is_global');
        });
    }
};
