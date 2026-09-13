<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streaming_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_account_id', 255);
            $table->string('label', 255)->nullable();
            $table->json('scopes');
            $table->text('refresh_token')->nullable();
            $table->timestamp('reauthorization_due_at')->nullable();
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->unique(['provider', 'provider_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streaming_accounts');
    }
};
