<?php

namespace App\Support;

use App\Candidates\Documentation;
use App\Models\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Sends one private evidence file to someone the caller has already
 * authorised: documents as attachments, videos inline (byte ranges for
 * native playback), with the stored MIME type, nosniff and a sandbox CSP.
 * No storage path or public URL is ever revealed.
 */
final class PrivateFileResponse
{
    /**
     * Whether this request starts reading the file (no Range, or a range
     * from byte 0). A video plays through many range requests; only the
     * first is a file access worth recording.
     */
    public static function isFirstRead(Request $request): bool
    {
        $range = $request->headers->get('Range');

        return $range === null || preg_match('/^bytes=0-/', $range) === 1;
    }

    public static function for(StoredFile $file): BinaryFileResponse
    {
        $video = str_starts_with($file->detected_mime_type, 'video/');
        $response = new BinaryFileResponse(Storage::disk(Documentation::DISK)->path($file->storage_path), 200, [
            'Content-Type' => $file->detected_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'; sandbox",
        ], false);
        $response->setContentDisposition(
            $video ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->original_name,
            Str::ascii($file->original_name) !== '' ? (string) preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($file->original_name)) : 'file',
        );

        return $response;
    }
}
