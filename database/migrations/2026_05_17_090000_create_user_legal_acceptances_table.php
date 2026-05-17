<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('document_version', 50);
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(['user_id', 'document_type', 'document_version'], 'user_legal_acceptance_unique');
            $table->index(['user_id', 'document_type'], 'user_legal_acceptance_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_legal_acceptances');
    }
};

