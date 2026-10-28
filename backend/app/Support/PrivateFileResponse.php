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
 * native playback) unless the caller asked for a download. The stored MIME
 * type, nosniff and a sandbox CSP are always set. No storage path or
 * public URL is ever revealed.
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

    public static function for(StoredFile $file, bool $forceDownload = false): BinaryFileResponse
    {
        $video = str_starts_with($file->detected_mime_type, 'video/');
        $name = self::filename($file);
        $response = new BinaryFileResponse(Storage::disk(Documentation::DISK)->path($file->storage_path), 200, [
            'Content-Type' => $file->detected_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'; sandbox",
        ], false);
        $response->setContentDisposition(
            $video && ! $forceDownload ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            self::fallback($name, $file->detected_mime_type),
        );

        return $response;
    }

    /**
     * The stored name, with the characters that would make the download
     * header illegal replaced, so a name never turns the response into an
     * error document.
     */
    private static function filename(StoredFile $file): string
    {
        $name = str_replace(['/', '\\', '%', "\r", "\n"], '_', $file->original_name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'file'.self::extension($file->detected_mime_type);
        }

        return $name;
    }

    private static function fallback(string $name, string $mime): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($name)), '._');
        if ($ascii === '') {
            return 'file'.self::extension($mime);
        }
        if (! str_contains($ascii, '.') && ($extension = self::extension($mime)) !== '') {
            $ascii .= $extension;
        }

        return $ascii;
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'video/mp4' => '.mp4',
            'video/webm' => '.webm',
            'application/pdf' => '.pdf',
            'application/msword' => '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            default => '',
        };
    }
}
