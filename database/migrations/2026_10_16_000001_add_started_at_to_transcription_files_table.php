<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcription_files', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('transcription_files', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
