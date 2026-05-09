<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ID que devuelve Google (sub) — nos permite reconocer al usuario
            // aunque cambie de email en Google. Indexado y único.
            $table->string('google_id')->nullable()->unique()->after('email');

            // Los usuarios que se registran con Google no tienen password local,
            // así que la columna debe poder ser NULL.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
            $table->string('password')->nullable(false)->change();
        });
    }
};
