<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** La versión del asset; sin ella Inertia contesta 409 en vez de la página. */
    private function inertiaVersion(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }

    public function test_inertia_xhr_response_is_not_stored(): void
    {
        $response = $this->get('/login', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_html_document_stays_cacheable_for_bfcache(): void
    {
        $response = $this->get('/login');

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
