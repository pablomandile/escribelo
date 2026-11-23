<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una traducción por idioma y por transcripción: traducir a inglés y después
     * a portugués no pisa la anterior. Retraducir al mismo idioma sí la reemplaza.
     */
    public function up(): void
    {
        Schema::create('transcription_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained()->cascadeOnDelete();
            $table->string('language', 8);
            $table->longText('text')->nullable();
            // queued | processing | completed | failed
            $table->string('status')->default('queued');
            // model, error, progress {chunk,total}, elapsed_seconds, tokens_used
            $table->json('metadata')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['transcription_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_translations');
    }
};
