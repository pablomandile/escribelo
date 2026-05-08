<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('remember_token');
        });

        Schema::table('transcription_files', function (Blueprint $table) {
            $table->string('cleaned_audio_path')->nullable()->after('clean_audio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        Schema::table('transcription_files', function (Blueprint $table) {
            $table->dropColumn('cleaned_audio_path');
        });
    }
};
