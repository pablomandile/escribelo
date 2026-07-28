<?php

namespace Tests\Feature;

use App\Jobs\ProcessTranscriptionFile;
use App\Models\TranscriptionFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetranscribeTest extends TestCase
{
    use RefreshDatabase;

    private function makeFile(User $user, array $attrs = []): TranscriptionFile
    {
        return TranscriptionFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'clase.mp3',
            'stored_path' => 'audios/'.$user->id.'/ok.mp3',
            'mime_type' => 'audio/mpeg',
            'size' => 1000,
            'model' => 'small',
            'status' => 'completed',
        ], $attrs));
    }

    public function test_retranscribe_updates_model_and_dispatches_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user);
        Storage::disk('local')->put($file->stored_path, 'dummy-audio');

        $this->actingAs($user)
            ->post(route('transcriptions.retranscribe', $file->id), ['model' => 'medium'])
            ->assertRedirect();

        $file->refresh();
        $this->assertSame('medium', $file->model);
        $this->assertSame('queued', $file->status);
        Queue::assertPushed(ProcessTranscriptionFile::class);
    }

    public function test_retranscribe_fails_when_audio_missing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user, ['stored_path' => 'audios/x/missing.mp3']);

        $this->actingAs($user)
            ->from(route('transcriptions.show', $file->id))
            ->post(route('transcriptions.retranscribe', $file->id), ['model' => 'medium'])
            ->assertSessionHasErrors('model');

        $file->refresh();
        $this->assertSame('small', $file->model);
        $this->assertSame('completed', $file->status);
        Queue::assertNothingPushed();
    }

    public function test_retranscribe_forbidden_for_other_user(): void
    {
        Queue::fake();
        Storage::fake('local');
        $owner = User::factory()->create(['approval_status' => 'approved']);
        $other = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($owner);
        Storage::disk('local')->put($file->stored_path, 'dummy');

        $this->actingAs($other)
            ->post(route('transcriptions.retranscribe', $file->id), ['model' => 'medium'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_retranscribe_rejects_invalid_model(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user);
        Storage::disk('local')->put($file->stored_path, 'dummy');

        $this->actingAs($user)
            ->from(route('transcriptions.show', $file->id))
            ->post(route('transcriptions.retranscribe', $file->id), ['model' => 'nonexistent'])
            ->assertSessionHasErrors('model');

        Queue::assertNothingPushed();
    }
}
