<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_provider_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->text('credentials')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('quota_total')->nullable();
            $table->unsignedBigInteger('quota_used')->default(0);
            $table->timestamp('quota_synced_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['user_id', 'storage_provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_accounts');
    }
};
