<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El worker se anuncia solo: el tunel de Cloudflare cambia de hostname en cada
 * arranque y antes habia que copiar la URL a mano en /admin/settings. Olvidarse
 * dejaba al server apuntando a un tunel muerto y las transcripciones colgadas
 * en "Esperando worker".
 */
class WorkerAnnounceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.remote_worker.token' => self::TOKEN]);
    }

    private function announce(array $body, ?string $token = self::TOKEN)
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->postJson(route('worker.announce'), $body, $headers);
    }

    public function test_guarda_la_url_con_el_token_correcto(): void
    {
        $response = $this->announce(['url' => 'https://algo-nuevo.trycloudflare.com']);

        $response->assertOk()->assertJson([
            'ok' => true,
            'url' => 'https://algo-nuevo.trycloudflare.com',
            'changed' => true,
        ]);

        $this->assertSame('https://algo-nuevo.trycloudflare.com', AppSetting::remoteWorkerUrl());
    }

    public function test_pisa_la_url_anterior(): void
    {
        AppSetting::set('remote_worker_url', 'https://tunel-viejo.trycloudflare.com');

        $this->announce(['url' => 'https://tunel-nuevo.trycloudflare.com'])->assertOk();

        $this->assertSame('https://tunel-nuevo.trycloudflare.com', AppSetting::remoteWorkerUrl());
    }

    public function test_le_saca_la_barra_final(): void
    {
        // cloudflared imprime la URL sin barra, pero si alguien la pega con una
        // terminariamos pidiendo //health.
        $this->announce(['url' => 'https://con-barra.trycloudflare.com/'])->assertOk();

        $this->assertSame('https://con-barra.trycloudflare.com', AppSetting::remoteWorkerUrl());
    }

    public function test_rechaza_token_invalido(): void
    {
        AppSetting::set('remote_worker_url', 'https://el-bueno.trycloudflare.com');

        $this->announce(['url' => 'https://del-atacante.com'], 'token-equivocado')
            ->assertStatus(401);

        $this->assertSame('https://el-bueno.trycloudflare.com', AppSetting::remoteWorkerUrl());
    }

    public function test_rechaza_sin_token(): void
    {
        $this->announce(['url' => 'https://cualquiera.trycloudflare.com'], null)
            ->assertStatus(401);
    }

    public function test_rechaza_http_sin_tls(): void
    {
        // El audio del usuario viaja por esta URL: sin https no va.
        $this->announce(['url' => 'http://inseguro.trycloudflare.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_rechaza_url_invalida(): void
    {
        $this->announce(['url' => 'no-soy-una-url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_falla_claro_si_el_server_no_tiene_token(): void
    {
        config(['services.remote_worker.token' => '']);

        $this->announce(['url' => 'https://algo.trycloudflare.com'], '')
            ->assertStatus(503);
    }

    public function test_no_requiere_csrf(): void
    {
        // La ruta esta exceptuada en bootstrap/app.php: el worker no tiene cookie.
        $this->withMiddleware()
            ->post(route('worker.announce'), ['url' => 'https://sin-csrf.trycloudflare.com'], [
                'Authorization' => 'Bearer '.self::TOKEN,
            ])
            ->assertOk();
    }
}
