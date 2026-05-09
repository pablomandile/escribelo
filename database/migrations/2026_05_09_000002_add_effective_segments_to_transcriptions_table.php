<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            // Segmentos derivados del texto editado, vía word-level diff contra el original.
            // NULL → la transcripción no tiene edición y se usan los segments originales.
            $table->json('effective_segments')->nullable()->after('edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('effective_segments');
        });
    }
};
