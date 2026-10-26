<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The homepage is the compiled React app (public/index.html), so this
     * check provides one in a temporary public directory.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $publicPath = storage_path('framework/testing/example-public-'.uniqid());
        File::ensureDirectoryExists($publicPath);
        File::put($publicPath.'/index.html', '<!doctype html><title>test</title>');
        $this->app->usePublicPath($publicPath);

        try {
            $response = $this->get('/');

            $response->assertStatus(200);
        } finally {
            File::deleteDirectory($publicPath);
        }
    }
}
