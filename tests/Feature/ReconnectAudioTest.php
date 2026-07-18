<?php

namespace Tests\Feature;

use App\Models\TranscriptionFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReconnectAudioTest extends TestCase
{
    use RefreshDatabase;

    private function makeFile(User $user, array $attrs = []): TranscriptionFile
    {
        return TranscriptionFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'clase.mp3',
            'stored_path' => 'audios/nope/missing.mp3',
            'mime_type' => 'audio/mpeg',
            'size' => 1000,
            'model' => 'small',
            'status' => 'completed',
        ], $attrs));
    }

    public function test_show_marks_audio_unavailable_when_file_missing(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user);

        $this->actingAs($user)
            ->get(route('transcriptions.show', $file->id))
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Transcriptions/Show')
                ->where('file.audio_available', false));
    }

    public function test_show_marks_audio_available_when_file_present(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user, ['stored_path' => 'audios/'.$user->id.'/ok.mp3']);
        Storage::disk('local')->put($file->stored_path, 'dummy-audio');

        $this->actingAs($user)
            ->get(route('transcriptions.show', $file->id))
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('file.audio_available', true));
    }

    public function test_reconnect_stores_file_and_updates_record(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user, [
            'stored_path' => 'C:\\Users\\x\\viejo.mp3',
            'cleaned_audio_path' => 'cleaned/old.mp3',
        ]);

        $upload = UploadedFile::fake()->create('nuevo.mp3', 120, 'audio/mpeg');

        $response = $this->actingAs($user)
            ->post(route('transcriptions.reconnect', $file->id), ['audio' => $upload]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $file->refresh();
        $this->assertStringStartsWith('audios/'.$user->id.'/', $file->stored_path);
        $this->assertNull($file->cleaned_audio_path);
        Storage::disk('local')->assertExists($file->stored_path);
    }

    public function test_reconnect_is_forbidden_for_other_user(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['approval_status' => 'approved']);
        $other = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($owner);

        $this->actingAs($other)
            ->post(route('transcriptions.reconnect', $file->id), [
                'audio' => UploadedFile::fake()->create('n.mp3', 100, 'audio/mpeg'),
            ])
            ->assertForbidden();
    }

    public function test_reconnect_rejects_non_audio_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['approval_status' => 'approved']);
        $file = $this->makeFile($user);

        $this->actingAs($user)
            ->from(route('transcriptions.show', $file->id))
            ->post(route('transcriptions.reconnect', $file->id), [
                'audio' => UploadedFile::fake()->create('nota.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors('audio');
    }
}
