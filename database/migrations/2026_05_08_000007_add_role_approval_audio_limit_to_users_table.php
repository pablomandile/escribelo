<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password')->index();
            $table->string('approval_status')->default('pending')->after('role')->index();
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->unsignedInteger('audio_limit')->nullable()->default(10)->after('approved_at');
        });

        // Existing users: assume the very first one is the admin and pre-approve all of them.
        DB::table('users')->update([
            'role' => 'admin',
            'approval_status' => 'approved',
            'approved_at' => now(),
            'audio_limit' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['role', 'approval_status', 'approved_at', 'audio_limit']);
        });
    }
};
