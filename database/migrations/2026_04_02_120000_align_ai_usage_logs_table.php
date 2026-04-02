<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('ai_usage_logs', 'external_id')) {
                $table->string('external_id')->nullable();
            }

            if (!Schema::hasColumn('ai_usage_logs', 'request_type')) {
                $table->string('request_type')->default('chat');
            }

            if (!Schema::hasColumn('ai_usage_logs', 'latency_ms')) {
                $table->integer('latency_ms')->nullable();
            }

            if (!Schema::hasColumn('ai_usage_logs', 'error_message')) {
                $table->text('error_message')->nullable();
            }
        });

        $this->updateCostPrecision();
        $this->ensureIndexes();
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            if ($this->hasIndex('ai_usage_logs', 'ai_usage_logs_household_created_at_index')) {
                $table->dropIndex('ai_usage_logs_household_created_at_index');
            }

            if ($this->hasIndex('ai_usage_logs', 'ai_usage_logs_created_at_index')) {
                $table->dropIndex('ai_usage_logs_created_at_index');
            }

            if ($this->hasIndex('ai_usage_logs', 'ai_usage_logs_household_id_index')) {
                $table->dropIndex('ai_usage_logs_household_id_index');
            }

            if ($this->hasIndex('ai_usage_logs', 'ai_usage_logs_external_id_index')) {
                $table->dropIndex('ai_usage_logs_external_id_index');
            }
        });
    }

    private function updateCostPrecision(): void
    {
        if (!Schema::hasColumn('ai_usage_logs', 'cost_usd')) {
            return;
        }

        $driver = DB::getDriverName();

        try {
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE ai_usage_logs ALTER COLUMN cost_usd TYPE NUMERIC(15,10)');
                DB::statement('ALTER TABLE ai_usage_logs ALTER COLUMN cost_usd SET DEFAULT 0');
                return;
            }

            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE ai_usage_logs MODIFY cost_usd DECIMAL(15,10) NOT NULL DEFAULT 0');
                return;
            }

            // Fallback for sqlite/other drivers when grammar supports it.
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->decimal('cost_usd', 15, 10)->default(0)->change();
            });
        } catch (\Throwable) {
            // No-op: keep existing precision if driver cannot alter it safely.
        }
    }

    private function ensureIndexes(): void
    {
        if (Schema::hasColumn('ai_usage_logs', 'external_id') && !$this->hasIndex('ai_usage_logs', 'ai_usage_logs_external_id_index')) {
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->index('external_id');
            });
        }

        if (Schema::hasColumn('ai_usage_logs', 'household_id') && !$this->hasIndex('ai_usage_logs', 'ai_usage_logs_household_id_index')) {
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->index('household_id');
            });
        }

        if (Schema::hasColumn('ai_usage_logs', 'created_at') && !$this->hasIndex('ai_usage_logs', 'ai_usage_logs_created_at_index')) {
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->index('created_at');
            });
        }

        if (
            Schema::hasColumn('ai_usage_logs', 'household_id')
            && Schema::hasColumn('ai_usage_logs', 'created_at')
            && !$this->hasIndex('ai_usage_logs', 'ai_usage_logs_household_created_at_index')
        ) {
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->index(['household_id', 'created_at'], 'ai_usage_logs_household_created_at_index');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $result = DB::selectOne(
                'SELECT 1
                 FROM pg_indexes
                 WHERE schemaname = current_schema()
                   AND tablename = ?
                   AND indexname = ?
                 LIMIT 1',
                [$table, $indexName]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return count($rows) > 0;
        }

        return false;
    }
};

