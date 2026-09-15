<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_files', function (Blueprint $table) {
            $table->boolean('is_starred')->default(false)->after('is_chunked');
            $table->index(['user_id', 'is_starred']);
        });

        Schema::table('virtual_folders', function (Blueprint $table) {
            $table->boolean('is_starred')->default(false)->after('name');
            $table->index(['user_id', 'is_starred']);
        });
    }

    public function down(): void
    {
        Schema::table('virtual_files', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_starred']);
            $table->dropColumn('is_starred');
        });

        Schema::table('virtual_folders', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_starred']);
            $table->dropColumn('is_starred');
        });
    }
};
