<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Regresión del 413 "Content Too Large" que aparecía al subir mp3 en Hostinger.
 *
 * Un POST más pesado que `post_max_size` hace que PHP descarte el body, y el
 * ValidatePostSize de fábrica respondía con la página de error cruda de Symfony
 * (que Inertia termina mostrando en un modal a pantalla completa). Ahora la
 * subida se rechaza con un error de formulario normal.
 */
class UploadSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_larger_than_post_max_size_redirects_with_a_readable_error(): void
    {
        $user = User::factory()->create(['approval_status' => 'approved']);

        $max = escribelo_ini_bytes(ini_get('post_max_size'));
        $this->assertGreaterThan(0, $max, 'El test necesita un post_max_size finito.');

        // No mandamos el cuerpo de verdad: falseamos el Content-Length, que es
        // lo único que mira el middleware (igual que hace PHP cuando corta la
        // subida y deja el body vacío).
        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->call('POST', route('transcriptions.store'), [], [], [], [
                'CONTENT_LENGTH' => (string) ($max + 1),
            ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors('files');

        $this->assertStringContainsString(
            'el servidor acepta hasta',
            session('errors')->first('files'),
        );
    }

    public function test_normal_sized_post_is_not_blocked(): void
    {
        $user = User::factory()->create(['approval_status' => 'approved']);

        // Sin archivos falla la validación de Laravel (422/redirect con errores
        // de campo), no el corte por tamaño: lo que importa es que llegue.
        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('transcriptions.store'), [])
            ->assertSessionHasErrors('files');

        $this->assertStringNotContainsString(
            'el servidor acepta hasta',
            session('errors')->first('files'),
        );
    }

    public function test_upload_limits_are_shared_with_the_frontend(): void
    {
        $user = User::factory()->create(['approval_status' => 'approved']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('uploadLimits.per_file', escribelo_upload_limits()['per_file'])
                ->where('uploadLimits.per_request', escribelo_upload_limits()['per_request']));
    }
}
