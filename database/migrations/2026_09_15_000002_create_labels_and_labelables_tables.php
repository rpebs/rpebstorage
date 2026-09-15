<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 30)->default('teal');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('labelables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->morphs('labelable');
            $table->timestamps();

            $table->unique(['label_id', 'labelable_id', 'labelable_type'], 'labelables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labelables');
        Schema::dropIfExists('labels');
    }
};
