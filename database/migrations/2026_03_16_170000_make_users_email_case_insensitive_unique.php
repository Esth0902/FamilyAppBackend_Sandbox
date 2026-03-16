<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('LOWER(email) as normalized_email, COUNT(*) as total')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_email')
            ->all();

        if (count($duplicates) > 0) {
            $preview = implode(', ', array_slice($duplicates, 0, 5));
            throw new RuntimeException(
                "Impossible d'activer l'unicité insensible à la casse : doublons trouvés ({$preview})."
            );
        }

        DB::statement('UPDATE users SET email = LOWER(email)');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
        });

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email))');
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users ((LOWER(email)))');
            return;
        }

        throw new RuntimeException("Driver SQL non supporté pour l'index e-mail insensible à la casse : {$driver}");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS users_email_ci_unique');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX users_email_ci_unique ON users');
        } else {
            throw new RuntimeException("Driver SQL non supporté pour le rollback e-mail insensible à la casse : {$driver}");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });
    }
};
