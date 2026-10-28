<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
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

    /**
     * The same page with another status (an administration page refused to
     * the signed-in account). A file response only streams its file for a
     * 2xx status, so the page is sent as an ordinary body here.
     */
    public static function withStatus(int $status): Response
    {
        $index = public_path('index.html');

        abort_unless(is_file($index), 404);

        return response((string) file_get_contents($index), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
