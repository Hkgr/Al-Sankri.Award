<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpaController extends Controller
{
    /**
     * Serve the compiled React app (deployed to public/index.html) for the
     * public pages listed in routes/web.php, so a direct visit or refresh
     * of /login, /account and so on works. Every other path keeps Laravel's
     * own handling: API routes, OAuth callbacks, /up, assets and real 404s.
     */
    public function __invoke(): BinaryFileResponse
    {
        $index = public_path('index.html');

        abort_unless(is_file($index), 404);

        return response()->file($index, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
