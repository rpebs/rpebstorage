<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('virtual_folder_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('storage_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('remote_ref');
            $table->unsignedBigInteger('size');
            $table->string('mime_type')->nullable();
            $table->boolean('is_chunked')->default(false);
            $table->timestamps();

            $table->index('name');
            $table->index(['user_id', 'virtual_folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_files');
    }
};
