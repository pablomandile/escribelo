<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            // Texto editado por el usuario. Cuando es NULL se usa el text original (Whisper).
            $table->longText('edited_text')->nullable()->after('text');
            $table->timestamp('edited_at')->nullable()->after('edited_text');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn(['edited_text', 'edited_at']);
        });
    }
};
