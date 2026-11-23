<?php

namespace Tests\Feature;

use App\Models\Transcription;
use App\Models\TranscriptionFile;
use App\Models\TranscriptionFolder;
use App\Models\TranscriptionTranslation;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;

    private function enableDemo(): void
    {
        config(['demo.enabled' => true]);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(database_path('demo/fixture.json')), true);
    }

    public function test_reset_refuses_to_run_outside_the_demo(): void
    {
        $real = User::factory()->create(['email' => 'real@example.com']);

        $this->artisan('escribelo:demo-reset')->assertFailed();

        $this->assertModelExists($real);
    }

    public function test_reset_replaces_everything_with_the_fixture(): void
    {
        $this->enableDemo();
        Storage::fake('local');
        Storage::disk('local')->put('audios/99/real.mp3', 'audio real');
        $real = User::factory()->create(['email' => 'real@example.com']);
        TranscriptionFolder::create(['user_id' => $real->id, 'name' => 'Privado']);

        $this->artisan('escribelo:demo-reset')->assertSuccessful();

        $fixture = $this->fixture();
        $this->assertModelMissing($real);
        $this->assertSame(1, User::count());
        $demo = User::sole();
        $this->assertSame(config('demo.email'), $demo->email);
        $this->assertTrue($demo->isApproved());
        $this->assertNotNull($demo->email_verified_at);

        $this->assertSame(count($fixture['files']), TranscriptionFile::count());
        $this->assertSame(0, TranscriptionFolder::where('name', 'Privado')->count());
        $this->assertFalse(Storage::disk('local')->exists('audios/99/real.mp3'));

        foreach ($fixture['files'] as $entry) {
            $file = TranscriptionFile::where('original_name', $entry['original_name'])->sole();
            $this->assertSame('completed', $file->status);
            $this->assertTrue(Storage::disk('local')->exists($file->stored_path), $file->stored_path);
            $this->assertSame($entry['folder'] ? end($entry['folder']) : null, $file->folder?->name);

            $transcription = $file->transcription;
            $this->assertSame($entry['transcription']['text'], $transcription->text);
            $this->assertSame(count($entry['transcription']['segments']), $transcription->segments()->count());
            $this->assertSame('completed', $transcription->summary_status);
            $this->assertEqualsCanonicalizing(
                array_column($entry['translations'], 'language'),
                $transcription->translations()->pluck('language')->all(),
            );
            // Fechas relativas a hoy.
            $this->assertTrue($file->created_at->isSameDay(now()->subDays($entry['days_ago'])));
        }
    }

    public function test_reset_twice_leaves_the_same_content(): void
    {
        $this->enableDemo();
        Storage::fake('local');

        $this->artisan('escribelo:demo-reset')->assertSuccessful();
        $this->artisan('escribelo:demo-reset')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(count($this->fixture()['files']), TranscriptionFile::count());
        $this->assertSame(count($this->fixture()['files']), Transcription::count());
    }

    public function test_the_fixture_is_complete(): void
    {
        foreach ($this->fixture()['files'] as $entry) {
            $this->assertFileExists(database_path('demo/'.$entry['audio']));
            $this->assertNotSame('', trim($entry['transcription']['text']), $entry['slug']);
            $this->assertNotEmpty($entry['transcription']['segments'], $entry['slug']);
            $this->assertStringContainsString('## ', $entry['summary']['text'], $entry['slug']);
            $this->assertStringNotContainsString('<0x', $entry['summary']['text'], $entry['slug']);
            foreach ($entry['translations'] as $translation) {
                $this->assertArrayHasKey($translation['language'], TranscriptionTranslation::LANGUAGES);
                $this->assertNotSame($entry['language'], $translation['language']);
            }
        }
    }

    public function test_demo_user_can_browse_but_not_change_anything(): void
    {
        $this->enableDemo();
        Storage::fake('local');
        Queue::fake();
        $this->artisan('escribelo:demo-reset')->assertSuccessful();
        $demo = User::sole();
        $file = TranscriptionFile::first();

        $this->actingAs($demo)->get(route('dashboard'))->assertOk();
        $this->actingAs($demo)->get(route('transcriptions.show', $file->id))->assertOk();
        $this->actingAs($demo)->get(route('transcriptions.download', [$file->id, 'txt']))->assertOk();

        $writes = [
            ['post', route('transcriptions.summary', $file->id), []],
            ['post', route('transcriptions.translation', $file->id), ['language' => 'pt']],
            ['post', route('transcriptions.retranscribe', $file->id), ['model' => 'medium']],
            ['patch', route('transcriptions.rename', $file->id), ['name' => 'hackeado']],
            ['patch', route('transcriptions.text.update', $file->id), ['text' => 'hackeado']],
            ['delete', route('transcriptions.destroy', $file->id), []],
            ['delete', route('transcriptions.audio.delete', $file->id), []],
            ['post', route('folders.store'), ['name' => 'Nueva']],
            ['patch', route('profile.update'), ['name' => 'x', 'email' => 'x@example.com']],
            ['delete', route('profile.destroy'), ['password' => 'demo1234']],
            ['put', route('password.update'), ['current_password' => 'demo1234', 'password' => 'nueva12345', 'password_confirmation' => 'nueva12345']],
        ];

        foreach ($writes as [$method, $url, $data]) {
            $this->actingAs($demo)
                ->from(route('transcriptions.show', $file->id))
                ->{$method}($url, $data)
                ->assertRedirect(route('transcriptions.show', $file->id))
                ->assertSessionHas('error');
        }

        $file->refresh();
        $this->assertModelExists($file);
        $this->assertNotSame('hackeado', $file->original_name);
        $this->assertSame(0, TranscriptionFolder::where('name', 'Nueva')->count());
        $this->assertTrue(password_verify('demo1234', $demo->fresh()->password));
        Queue::assertNothingPushed();
    }

    public function test_demo_blocks_account_screens_and_the_server_file_browser(): void
    {
        $this->enableDemo();
        Storage::fake('local');
        $this->artisan('escribelo:demo-reset')->assertSuccessful();

        foreach (['register', 'password.request', 'auth.google.redirect'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }

        $this->actingAs(User::sole())
            ->getJson(route('library.browse'))
            ->assertForbidden();
    }

    public function test_login_page_shares_the_demo_account_only_in_demo_mode(): void
    {
        $this->get(route('login'))->assertInertia(fn ($page) => $page->where('demo', null));

        $this->enableDemo();
        $this->get(route('login'))->assertInertia(fn ($page) => $page
            ->where('demo.email', config('demo.email'))
            ->where('demo.password', config('demo.password')));
    }

    public function test_demo_user_can_log_in_and_out(): void
    {
        $this->enableDemo();
        Storage::fake('local');
        $this->artisan('escribelo:demo-reset')->assertSuccessful();

        $this->post(route('login'), ['email' => config('demo.email'), 'password' => config('demo.password')])
            ->assertRedirect();
        $this->assertAuthenticated();

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_without_the_flag_nothing_is_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->create(['approval_status' => 'approved']);

        $this->actingAs($user)->post(route('folders.store'), ['name' => 'Nueva'])->assertSessionDoesntHaveErrors();

        $this->assertSame(1, TranscriptionFolder::where('name', 'Nueva')->count());
    }

    public function test_the_nightly_reset_is_scheduled_only_in_demo_mode(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'escribelo-demo-reset');

        $this->assertNotNull($event);
        $this->assertSame('America/Argentina/Buenos_Aires', $event->timezone);
        $this->assertFalse($event->filtersPass($this->app));

        $this->enableDemo();
        $this->assertTrue($event->filtersPass($this->app));
    }
}
