<?php

namespace App\Candidates;

use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Checks an uploaded Stage 3 file before anything is stored: its real size
 * (exact byte limits), its extension, the MIME type the server detects from
 * the content (finfo), and the format's own structure. A renamed executable
 * or web page fails the detection; a document with macros fails the
 * structure check. This is not a malware scan: accepted files keep the scan
 * status `pending`.
 */
class EvidenceInspector
{
    /** 25 MiB. */
    public const DOCUMENT_MAX_BYTES = 26_214_400;

    /** 50 MiB. */
    public const VIDEO_MAX_BYTES = 52_428_800;

    /**
     * 64 KiB for a DOCX's `[Content_Types].xml`: a list of part types,
     * usually 1–3 KB even for long documents. Larger is refused.
     */
    public const CONTENT_TYPES_MAX_BYTES = 65_536;

    /**
     * Per extension: the MIME type stored for it once the structure is
     * verified, and the types finfo may report for genuine files.
     *
     * @var array<string, array{mime: string, detected: list<string>}>
     */
    public const FORMATS = [
        'pdf' => ['mime' => 'application/pdf', 'detected' => ['application/pdf']],
        'doc' => ['mime' => 'application/msword', 'detected' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office']],
        'docx' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'detected' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ],
        'mp4' => ['mime' => 'video/mp4', 'detected' => ['video/mp4', 'video/x-m4v', 'video/quicktime']],
        'webm' => ['mime' => 'video/webm', 'detected' => ['video/webm', 'audio/webm', 'video/x-matroska']],
    ];

    /**
     * Per kind of attachment: allowed extensions and the byte limit.
     *
     * @var array<string, array{extensions: list<string>, max_bytes: int}>
     */
    public const KINDS = [
        'document' => ['extensions' => ['pdf', 'doc', 'docx'], 'max_bytes' => self::DOCUMENT_MAX_BYTES],
        'video' => ['extensions' => ['mp4', 'webm'], 'max_bytes' => self::VIDEO_MAX_BYTES],
    ];

    /**
     * @return array{ok: true, extension: string, mime: string, size: int}|array{ok: false, message: string}
     */
    public function inspect(UploadedFile $file, string $kind): array
    {
        $rules = self::KINDS[$kind];
        $path = $file->getRealPath();
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = implode('، ', array_map('strtoupper', $rules['extensions']));

        if (! in_array($extension, $rules['extensions'], true)) {
            return $this->refuse("نوع الملف غير مقبول هنا. الأنواع المقبولة: {$allowed}.");
        }

        $size = $path === false ? false : filesize($path);
        if ($size === false || $size === 0) {
            return $this->refuse('الملف فارغ أو تعذّرت قراءته.');
        }
        if ($size > $rules['max_bytes']) {
            return $this->refuse('حجم الملف أكبر من الحد المسموح ('.($rules['max_bytes'] / 1_048_576).' ميغابايت).');
        }

        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($detected, self::FORMATS[$extension]['detected'], true) || ! $this->hasStructure($extension, $path)) {
            return $this->refuse("محتوى الملف لا يطابق صيغة {$this->label($extension)} سليمة، أو يحتوي على وحدات ماكرو. الأنواع المقبولة: {$allowed}.");
        }

        return ['ok' => true, 'extension' => $extension, 'mime' => self::FORMATS[$extension]['mime'], 'size' => $size];
    }

    private function hasStructure(string $extension, string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 64);

        return match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'doc' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'docx' => $this->isWordDocumentWithoutMacros($path),
            'mp4' => substr($head, 4, 4) === 'ftyp',
            'webm' => str_starts_with($head, "\x1A\x45\xDF\xA3") && str_contains($head, 'webm'),
            default => false,
        };
    }

    /**
     * A DOCX is a ZIP package with a main Word document part and no VBA
     * project or macro-enabled content type.
     *
     * Only `[Content_Types].xml` is decompressed. The upload limit bounds
     * the compressed archive, not what an entry inflates to, so the entry's
     * declared size is checked first and the read itself stops one byte
     * past CONTENT_TYPES_MAX_BYTES (the declared size can be forged).
     */
    private function isWordDocumentWithoutMacros(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $entry = $zip->statName('[Content_Types].xml');
            if ($entry === false || $entry['size'] > self::CONTENT_TYPES_MAX_BYTES || $zip->locateName('word/document.xml') === false) {
                return false;
            }
            $types = $zip->getFromName('[Content_Types].xml', self::CONTENT_TYPES_MAX_BYTES + 1);
            if ($types === false || strlen($types) > self::CONTENT_TYPES_MAX_BYTES || stripos($types, 'macroEnabled') !== false) {
                return false;
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (preg_match('/vbaProject\.bin$|vbaData\.xml$/i', (string) $zip->getNameIndex($index)) === 1) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }

    private function label(string $extension): string
    {
        return strtoupper($extension);
    }

    /**
     * @return array{ok: false, message: string}
     */
    private function refuse(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
