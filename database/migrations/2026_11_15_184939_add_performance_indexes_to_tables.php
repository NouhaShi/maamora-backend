<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->index('group_id');
            $table->index('created_at');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->index('status');
            $table->index('created_at');
        });

        Schema::table('group_progress_histories', function (Blueprint $table) {
            $table->index(['group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('group_progress_histories', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'created_at']);
        });
    }
};
