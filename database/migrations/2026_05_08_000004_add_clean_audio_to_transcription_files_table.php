<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcription_files', function (Blueprint $table) {
            $table->boolean('clean_audio')->default(false)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('transcription_files', function (Blueprint $table) {
            $table->dropColumn('clean_audio');
        });
    }
};
