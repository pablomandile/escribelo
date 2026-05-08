<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->longText('summary')->nullable()->after('text');
            $table->json('summary_metadata')->nullable()->after('summary');
            $table->string('summary_status')->default('idle')->after('summary_metadata');
            $table->timestamp('summary_generated_at')->nullable()->after('summary_status');
        });

        Schema::create('groq_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('requests_count')->default(0);
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groq_usage');

        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_metadata', 'summary_status', 'summary_generated_at']);
        });
    }
};
