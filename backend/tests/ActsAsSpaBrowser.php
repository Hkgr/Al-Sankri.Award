<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;

/**
 * Drives the API the way the React app does in a browser: one cookie jar
 * (session and XSRF-TOKEN cookies, exactly as the server set them), the
 * site's Origin, and the X-XSRF-TOKEN header. CSRF verification is really
 * enforced (Laravel skips it in tests by default), and no authentication
 * state is carried between requests except through the cookies.
 */
trait ActsAsSpaBrowser
{
    /**
     * @var array<string, string>
     */
    protected array $cookieJar = [];

    protected function enforceCsrfProtection(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function browser(string $method, string $uri, array $data = [], bool $withCsrfHeader = true): TestResponse
    {
        $this->forgetRequestState();

        $origin = rtrim((string) config('app.url'), '/');
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin.'/',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ];
        if ($withCsrfHeader && isset($this->cookieJar['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookieJar['XSRF-TOKEN'];
        }

        $content = null;
        $parameters = [];
        if ($method === 'GET') {
            $parameters = $data;
        } else {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($data, JSON_THROW_ON_ERROR);
        }

        $response = $this->call($method, $uri, $parameters, $this->cookieJar, [], $server, $content);
        $this->rememberCookies($response);

        return $response;
    }

    /**
     * A full-page navigation (no JSON Accept header), as when the browser
     * follows a link or a redirect from Google.
     *
     * @param  array<string, mixed>  $query
     */
    protected function navigate(string $uri, array $query = []): TestResponse
    {
        $this->forgetRequestState();

        $response = $this->call('GET', $uri, $query, $this->cookieJar, [], ['HTTP_ACCEPT' => 'text/html']);
        $this->rememberCookies($response);

        return $response;
    }

    protected function startCsrfSession(): void
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    protected function sessionCookieName(): string
    {
        return (string) config('session.cookie');
    }

    /**
     * The test application lives across requests, unlike PHP in production.
     * Drop the user and session data held in memory by the previous request,
     * so only the cookies (and the stored session they point to) count.
     */
    private function forgetRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        Socialite::forgetDrivers();
    }

    private function rememberCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookieJar[$cookie->getName()]);
            } else {
                $this->cookieJar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }
    }
}
