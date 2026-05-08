<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->decimal('start_seconds', 10, 3)->default(0);
            $table->decimal('end_seconds', 10, 3)->default(0);
            $table->text('text');
            $table->timestamps();

            $table->index(['transcription_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_segments');
    }
};
