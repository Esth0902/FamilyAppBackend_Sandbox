<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('accepted_cgu_version', 50)->nullable()->after('must_change_password');
            $table->timestamp('accepted_cgu_at')->nullable()->after('accepted_cgu_version');
            $table->string('accepted_privacy_policy_version', 50)->nullable()->after('accepted_cgu_at');
            $table->timestamp('accepted_privacy_policy_at')->nullable()->after('accepted_privacy_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'accepted_cgu_version',
                'accepted_cgu_at',
                'accepted_privacy_policy_version',
                'accepted_privacy_policy_at',
            ]);
        });
    }
};

