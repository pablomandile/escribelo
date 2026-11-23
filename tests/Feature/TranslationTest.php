<?php

namespace Tests\Feature;

use App\Jobs\TranslateTranscription;
use App\Models\AppSetting;
use App\Models\Transcription;
use App\Models\TranscriptionFile;
use App\Models\User;
use App\Services\Translator\TranslationPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranscription(User $user, string $text = 'Hola a todos. Hoy vamos a hablar de traducciones.', array $fileAttrs = []): Transcription
    {
        $file = TranscriptionFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'clase.mp3',
            'stored_path' => 'audios/'.$user->id.'/ok.mp3',
            'mime_type' => 'audio/mpeg',
            'size' => 1000,
            'model' => 'small',
            'language' => 'es',
            'status' => 'completed',
        ], $fileAttrs));

        return Transcription::create(['transcription_file_id' => $file->id, 'text' => $text]);
    }

    private function approvedUser(): User
    {
        return User::factory()->create(['approval_status' => 'approved']);
    }

    /** Ollama local: /api/tags para el chequeo del controlador, /api/chat devuelve "[EN] <texto>". */
    private function fakeLocalOllama(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []]),
            '*/api/chat' => function (HttpRequest $request) {
                $text = collect($request['messages'])->firstWhere('role', 'user')['content'];

                return Http::response([
                    'message' => ['content' => '[EN] '.$text],
                    'prompt_eval_count' => 10,
                    'eval_count' => 20,
                ]);
            },
        ]);
    }

    public function test_translate_queues_a_translation_for_the_chosen_language(): void
    {
        Queue::fake();
        $this->fakeLocalOllama();
        $user = $this->approvedUser();
        $transcription = $this->makeTranscription($user);

        $this->actingAs($user)
            ->post(route('transcriptions.translation', $transcription->transcription_file_id), ['language' => 'en'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $translation = $transcription->translations()->first();
        $this->assertSame('en', $translation->language);
        $this->assertSame('queued', $translation->status);
        Queue::assertPushed(TranslateTranscription::class, fn ($job) => $job->translation->is($translation));
    }

    public function test_retranslating_the_same_language_reuses_the_row(): void
    {
        Queue::fake();
        $this->fakeLocalOllama();
        $user = $this->approvedUser();
        $transcription = $this->makeTranscription($user);
        $transcription->translations()->create(['language' => 'en', 'status' => 'completed', 'text' => 'Old']);

        $this->actingAs($user)
            ->post(route('transcriptions.translation', $transcription->transcription_file_id), ['language' => 'en'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $transcription->translations()->count());
        $this->assertNull($transcription->translations()->first()->text);
    }

    public function test_translate_rejects_languages_outside_the_list(): void
    {
        Queue::fake();
        $user = $this->approvedUser();
        $transcription = $this->makeTranscription($user);

        $this->actingAs($user)
            ->from(route('transcriptions.show', $transcription->transcription_file_id))
            ->post(route('transcriptions.translation', $transcription->transcription_file_id), ['language' => 'fr'])
            ->assertSessionHasErrors('language');

        Queue::assertNothingPushed();
    }

    public function test_translate_is_forbidden_for_another_user(): void
    {
        Queue::fake();
        $transcription = $this->makeTranscription($this->approvedUser());

        $this->actingAs($this->approvedUser())
            ->post(route('transcriptions.translation', $transcription->transcription_file_id), ['language' => 'en'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_job_translates_in_chunks_and_joins_them(): void
    {
        $this->fakeLocalOllama();
        $user = $this->approvedUser();
        // Varias oraciones: más largo que un trozo, así se corta en al menos dos.
        $sentence = 'Esta es una oración de prueba para la traducción. ';
        $text = trim(str_repeat($sentence, (int) ceil(TranslationPrompt::MAX_CHARS_PER_CHUNK * 1.5 / strlen($sentence))));
        $transcription = $this->makeTranscription($user, $text);
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'queued']);

        (new TranslateTranscription($translation))->handle();

        $translation->refresh();
        $chunks = TranslationPrompt::chunk($text);
        $this->assertGreaterThanOrEqual(2, count($chunks));
        $this->assertSame('completed', $translation->status);
        $this->assertSame(
            implode("\n\n", array_map(fn ($c) => '[EN] '.$c, $chunks)),
            $translation->text,
        );
        $this->assertSame(count($chunks), $translation->metadata['chunks']);
        $this->assertSame(30 * count($chunks), $translation->metadata['tokens_used']);
        $this->assertNotNull($translation->generated_at);

        // El prompt pide el idioma de destino y menciona el de origen.
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/chat')
            && str_contains($r['messages'][0]['content'], 'inglés')
            && str_contains($r['messages'][0]['content'], 'está en español'));
    }

    public function test_job_uses_the_edited_text_when_there_is_one(): void
    {
        $this->fakeLocalOllama();
        $transcription = $this->makeTranscription($this->approvedUser(), 'Texto original.');
        $transcription->update(['edited_text' => 'Texto corregido.']);
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'queued']);

        (new TranslateTranscription($translation))->handle();

        $this->assertSame('[EN] Texto corregido.', $translation->fresh()->text);
    }

    public function test_job_does_nothing_if_cancelled_while_queued(): void
    {
        Http::fake();
        $transcription = $this->makeTranscription($this->approvedUser());
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'failed']);

        (new TranslateTranscription($translation))->handle();

        Http::assertNothingSent();
        $this->assertSame('failed', $translation->fresh()->status);
    }

    public function test_job_marks_failed_with_the_ollama_error(): void
    {
        Http::fake(['*/api/chat' => Http::response('boom', 500)]);
        $transcription = $this->makeTranscription($this->approvedUser());
        $translation = $transcription->translations()->create(['language' => 'pt', 'status' => 'queued']);

        (new TranslateTranscription($translation))->handle();

        $translation->refresh();
        $this->assertSame('failed', $translation->status);
        $this->assertStringContainsString('500', $translation->metadata['error']);
    }

    public function test_job_in_host_mode_goes_through_the_remote_worker(): void
    {
        AppSetting::set('mode', 'host');
        AppSetting::set('remote_worker_url', 'https://worker.test');
        config(['services.remote_worker.token' => 'secreto']);
        Http::fake([
            'https://worker.test/translate' => Http::response(
                json_encode(['status' => 'working'])."\n"
                .json_encode(['result' => ['text' => 'Hello everyone.', 'tokens_used' => 42, 'model' => 'gemma4:e4b']])."\n",
            ),
        ]);
        $transcription = $this->makeTranscription($this->approvedUser(), 'Hola a todos.');
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'queued']);

        (new TranslateTranscription($translation))->handle();

        $translation->refresh();
        $this->assertSame('completed', $translation->status);
        $this->assertSame('Hello everyone.', $translation->text);
        $this->assertSame('gemma4:e4b', $translation->metadata['model']);
        $this->assertSame('remote', $translation->metadata['provider']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer secreto')
            && $r['text'] === 'Hola a todos.'
            && str_contains($r['system'], 'inglés'));
    }

    public function test_host_mode_explains_an_outdated_worker(): void
    {
        AppSetting::set('mode', 'host');
        AppSetting::set('remote_worker_url', 'https://worker.test');
        config(['services.remote_worker.token' => 'secreto']);
        Http::fake(['https://worker.test/translate' => Http::response(['detail' => 'Not Found'], 404)]);
        $transcription = $this->makeTranscription($this->approvedUser());
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'queued']);

        (new TranslateTranscription($translation))->handle();

        $this->assertStringContainsString('reinicialo', $translation->fresh()->metadata['error']);
    }

    protected function tearDown(): void
    {
        TranslateTranscription::$processStartedAt = null;
        parent::tearDown();
    }

    public function test_job_hands_over_to_a_new_process_when_the_time_budget_runs_out(): void
    {
        Queue::fake();
        Http::fake();
        config(['queue.process_time_budget' => 1800]);
        // El proceso de queue:work lleva 1700 s vivo: no le alcanza para otro trozo.
        TranslateTranscription::$processStartedAt = microtime(true) - 1700;
        $sentence = 'Esta es una oración de prueba para la traducción. ';
        $text = trim(str_repeat($sentence, (int) ceil(TranslationPrompt::MAX_CHARS_PER_CHUNK * 1.5 / strlen($sentence))));
        $transcription = $this->makeTranscription($this->approvedUser(), $text);
        $translation = $transcription->translations()->create([
            'language' => 'en',
            'status' => 'queued',
            'text' => '[EN] Parte uno.',
            'metadata' => ['tokens_used' => 30, 'working_seconds' => 80],
        ]);

        (new TranslateTranscription($translation, 1))->handle();

        Http::assertNothingSent();
        $translation->refresh();
        $this->assertSame('queued', $translation->status);
        $this->assertSame('[EN] Parte uno.', $translation->text);
        $this->assertSame(1, $translation->metadata['progress']['chunk']);
        Queue::assertPushed(TranslateTranscription::class, fn ($job) => $job->fromChunk === 1
            && $job->translation->is($translation)
            && $job->delay !== null);
    }

    public function test_a_continuation_appends_to_what_was_already_translated(): void
    {
        $this->fakeLocalOllama();
        $sentence = 'Esta es una oración de prueba para la traducción. ';
        $text = trim(str_repeat($sentence, (int) ceil(TranslationPrompt::MAX_CHARS_PER_CHUNK * 1.5 / strlen($sentence))));
        $chunks = TranslationPrompt::chunk($text);
        $transcription = $this->makeTranscription($this->approvedUser(), $text);
        $translation = $transcription->translations()->create([
            'language' => 'en',
            'status' => 'queued',
            'text' => '[EN] '.$chunks[0],
            'metadata' => ['tokens_used' => 30, 'working_seconds' => 80, 'model' => 'gemma4:e4b'],
        ]);

        (new TranslateTranscription($translation, 1))->handle();

        $translation->refresh();
        $this->assertSame('completed', $translation->status);
        $this->assertSame(implode("\n\n", array_map(fn ($c) => '[EN] '.$c, $chunks)), $translation->text);
        $this->assertSame(30 * count($chunks), $translation->metadata['tokens_used']);
        $this->assertGreaterThanOrEqual(80, $translation->metadata['working_seconds']);
        // Solo se tradujeron los trozos que faltaban.
        Http::assertSentCount(count($chunks) - 1);
    }

    public function test_without_a_budget_the_job_never_hands_over(): void
    {
        $this->fakeLocalOllama();
        config(['queue.process_time_budget' => 0]);
        TranslateTranscription::$processStartedAt = microtime(true) - 100000;
        $transcription = $this->makeTranscription($this->approvedUser(), 'Hola.');
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'queued']);

        (new TranslateTranscription($translation))->handle();

        $this->assertSame('completed', $translation->fresh()->status);
    }

    public function test_cancel_marks_failed_and_removes_the_queued_job(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->approvedUser();
        $transcription = $this->makeTranscription($user);
        $translation = $transcription->translations()->create(['language' => 'en', 'status' => 'queued']);
        $other = $transcription->translations()->create(['language' => 'pt', 'status' => 'queued']);
        TranslateTranscription::dispatch($translation);
        TranslateTranscription::dispatch($other);
        $this->assertSame(2, DB::table('jobs')->count());

        $this->actingAs($user)
            ->delete(route('transcriptions.translation.cancel', [$transcription->transcription_file_id, 'en']))
            ->assertRedirect();

        $this->assertSame('failed', $translation->fresh()->status);
        $this->assertSame('Cancelado manualmente.', $translation->fresh()->metadata['error']);
        // Solo se va el job de esa traducción; el de portugués sigue en cola.
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('queued', $other->fresh()->status);
    }

    public function test_show_sends_the_translations_by_language(): void
    {
        $user = $this->approvedUser();
        $transcription = $this->makeTranscription($user);
        $transcription->translations()->create([
            'language' => 'en',
            'status' => 'completed',
            'text' => 'Hello.',
            'metadata' => ['model' => 'gemma4:e4b', 'elapsed_seconds' => 12],
        ]);

        $this->actingAs($user)
            ->get(route('transcriptions.show', $transcription->transcription_file_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('file.transcription.translations.en.text', 'Hello.')
                ->where('file.transcription.translations.en.model', 'gemma4:e4b')
                ->where('translationLanguages.pt', 'Portugués'));
    }

    public function test_chunks_respect_the_limit_and_keep_all_the_text(): void
    {
        $text = str_repeat('Una oración corta. Otra más larga que la anterior, con coma. ', 400);
        $chunks = TranslationPrompt::chunk($text, 1000);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1000, mb_strlen($chunk));
        }
        $normalize = fn (string $s) => preg_replace('/\s+/', ' ', trim($s));
        $this->assertSame($normalize($text), $normalize(implode(' ', $chunks)));
    }

    public function test_clean_strips_wrappers_the_model_sometimes_adds(): void
    {
        $this->assertSame('Hello.', TranslationPrompt::clean("```\nHello.\n```"));
        $this->assertSame('Hello.', TranslationPrompt::clean('Translation: Hello.'));
        $this->assertSame('Olá.', TranslationPrompt::clean('Traducción al portugués: Olá.'));
        $this->assertSame('Hola: qué tal.', TranslationPrompt::clean('Hola: qué tal.'));
    }
}
