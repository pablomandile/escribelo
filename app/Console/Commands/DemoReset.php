<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Transcription;
use App\Models\TranscriptionFile;
use App\Models\TranscriptionFolder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deja la demo como recién instalada: borra TODOS los usuarios (y con ellos, en
 * cascada, sus carpetas, archivos, transcripciones, resúmenes y traducciones),
 * vacía los audios del disco y carga el contenido pregrabado de
 * database/demo/fixture.json. No llama a ninguna IA.
 *
 * Solo corre con APP_DEMO=true: en la productiva borraría todo.
 */
class DemoReset extends Command
{
    protected $signature = 'escribelo:demo-reset';

    protected $description = 'Resetea la demo con el contenido pregrabado (solo con APP_DEMO=true)';

    /** Carpetas del disco local donde la app guarda archivos de los usuarios. */
    private const STORAGE_DIRS = ['audios', 'cleaned', 'transcripts', 'uploads'];

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->error('Esto no es la demo (APP_DEMO no está en true). No se toca nada.');

            return self::FAILURE;
        }

        $fixture = json_decode(file_get_contents(database_path('demo/fixture.json')), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($fixture) {
            // Cascada: transcription_folders, transcription_files → transcriptions →
            // segments y translations, groq_usage.
            User::query()->delete();
            foreach (['sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache', 'cache_locks'] as $table) {
                DB::table($table)->delete();
            }

            // Lo que la demo muestra en pantalla (worker en la PC por túnel).
            AppSetting::set('mode', 'host');
            AppSetting::set('remote_worker_url', '');

            $user = $this->createUser();
            $this->loadFixture($user, $fixture['files']);
        });

        // Los archivos se tocan fuera de la transacción, después de que la base quedó bien.
        $disk = Storage::disk('local');
        foreach (self::STORAGE_DIRS as $dir) {
            $disk->deleteDirectory($dir);
        }
        $user = User::where('email', config('demo.email'))->firstOrFail();
        foreach ($fixture['files'] as $entry) {
            $disk->put("audios/{$user->id}/{$entry['slug']}.mp3", file_get_contents(database_path('demo/'.$entry['audio'])));
        }

        $this->info('Demo reseteada: '.count($fixture['files']).' audios para '.$user->email.'.');

        return self::SUCCESS;
    }

    private function createUser(): User
    {
        $user = User::create([
            'name' => 'Cuenta demo',
            'email' => config('demo.email'),
            'password' => bcrypt(config('demo.password')),
            'role' => 'user',
            'approval_status' => 'approved',
            'approved_at' => now(),
            'audio_limit' => 10,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function loadFixture(User $user, array $files): void
    {
        $folders = [];

        foreach ($files as $entry) {
            $folderId = null;
            foreach ($entry['folder'] ?? [] as $name) {
                $key = $folderId.'/'.$name;
                $folders[$key] ??= TranscriptionFolder::create([
                    'user_id' => $user->id,
                    'parent_id' => $folderId,
                    'name' => $name,
                ])->id;
                $folderId = $folders[$key];
            }

            // Fechas relativas a hoy, para que la demo siempre se vea al día.
            $createdAt = Carbon::now()->subDays($entry['days_ago'])->setTime(10 + $entry['days_ago'] % 8, 17);
            $startedAt = $createdAt->copy()->addSeconds(8);
            $processedAt = $startedAt->copy()->addSeconds($entry['processing_seconds']);
            $audioPath = database_path('demo/'.$entry['audio']);

            $file = new TranscriptionFile([
                'user_id' => $user->id,
                'transcription_folder_id' => $folderId,
                'original_name' => $entry['original_name'],
                'stored_path' => "audios/{$user->id}/{$entry['slug']}.mp3",
                'mime_type' => 'audio/mpeg',
                'size' => filesize($audioPath),
                'duration_seconds' => $entry['duration_seconds'],
                'language' => $entry['language'],
                'model' => $entry['model'],
                'status' => 'completed',
                'progress' => 100,
                'started_at' => $startedAt,
                'processed_at' => $processedAt,
            ]);
            $file->forceFill(['created_at' => $createdAt, 'updated_at' => $processedAt])->save();

            $summaryAt = $processedAt->copy()->addMinutes(3)->addSeconds($entry['summary']['metadata']['elapsed_seconds'] ?? 0);
            $transcription = new Transcription([
                'transcription_file_id' => $file->id,
                'text' => $entry['transcription']['text'],
                'metadata' => $entry['transcription']['metadata'],
                'summary' => $entry['summary']['text'],
                'summary_metadata' => $entry['summary']['metadata'],
                'summary_status' => 'completed',
                'summary_generated_at' => $summaryAt,
            ]);
            $transcription->forceFill(['created_at' => $processedAt, 'updated_at' => $summaryAt])->save();

            $now = now();
            DB::table('transcription_segments')->insert(array_map(fn ($segment, $i) => [
                'transcription_id' => $transcription->id,
                'position' => $i,
                'start_seconds' => $segment[0],
                'end_seconds' => $segment[1],
                'text' => $segment[2],
                'created_at' => $now,
                'updated_at' => $now,
            ], $entry['transcription']['segments'], array_keys($entry['transcription']['segments'])));

            foreach ($entry['translations'] as $i => $translation) {
                $at = $summaryAt->copy()->addMinutes(5 * ($i + 1));
                $transcription->translations()->create([
                    'language' => $translation['language'],
                    'text' => $translation['text'],
                    'status' => 'completed',
                    'metadata' => $translation['metadata'],
                    'generated_at' => $at,
                ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            }
        }
    }
}
