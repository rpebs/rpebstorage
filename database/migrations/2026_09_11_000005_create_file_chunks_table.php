<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('remote_file_id');
            $table->unsignedBigInteger('size');
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->unique(['virtual_file_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_chunks');
    }
};
