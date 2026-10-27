<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Direct visits and refreshes of the React pages get the compiled
 * index.html, while API routes, OAuth callbacks, /up, assets and unknown
 * paths keep Laravel's own handling.
 */
class SpaRoutingTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicPath = storage_path('framework/testing/spa-public-'.uniqid());
        File::ensureDirectoryExists($this->publicPath.'/assets');
        File::put($this->publicPath.'/index.html', '<!doctype html><html lang="ar" dir="rtl"><div id="root"></div></html>');
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_each_public_page_serves_the_react_app(): void
    {
        foreach (['/', '/login', '/register', '/forgot-password', '/reset-password?token=abc&email=a%40b.org', '/verify-email?status=verified', '/account', '/dashboard', '/dashboard/basic-information', '/dashboard/nomination-details', '/dashboard/documentation', '/dashboard/review', '/policies/privacy-policy', '/policies/terms-of-use'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertSame(realpath($this->publicPath.'/index.html'), $response->baseResponse->getFile()->getRealPath(), $path);
            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_other_paths_are_not_swallowed_by_the_react_app(): void
    {
        $this->get('/no-such-page')->assertNotFound();
        $this->get('/account/settings')->assertNotFound();
        $this->get('/dashboard/stage-2')->assertNotFound();
        $this->get('/dashboard/basic-information/edit')->assertNotFound();
        $this->get('/policies/cookies')->assertNotFound();
        $this->getJson('/api/candidate/overview')->assertUnauthorized();
        $this->getJson('/api/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/up')->assertOk();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->get('/auth/google/callback')->assertRedirect('/login?google=unavailable');
        $this->get('/assets/missing.js')->assertNotFound();
    }

    public function test_pages_are_unavailable_until_the_frontend_is_built(): void
    {
        File::delete($this->publicPath.'/index.html');

        $this->get('/login')->assertNotFound();
    }
}
