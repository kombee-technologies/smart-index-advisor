<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Stores stats import uploads with server-side type detection.
 *
 * Client-supplied extensions and filenames are never used when writing to disk.
 */
class StatsImportUpload
{
    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['csv', 'json', 'txt'];

    /** @var array<string, string> */
    private const MIME_TO_EXTENSION = [
        'application/json' => 'json',
        'text/json' => 'json',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
        'text/plain' => 'txt',
    ];

    public function store(UploadedFile $file): string
    {
        $extension = $this->resolveSafeExtension($file);
        $directory = storage_path('app/smart-index-advisor-uploads');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create upload directory.');
        }

        $filename = uniqid('ia_', true).'.'.$extension;
        $file->move($directory, $filename);

        return $directory.DIRECTORY_SEPARATOR.$filename;
    }

    public function resolveSafeExtension(UploadedFile $file): string
    {
        $clientExtension = strtolower($file->getClientOriginalExtension());

        if (! in_array($clientExtension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Invalid file type. Only CSV, JSON, and TXT files are allowed.');
        }

        $mime = strtolower($file->getMimeType() ?? '');

        if (isset(self::MIME_TO_EXTENSION[$mime])) {
            return self::MIME_TO_EXTENSION[$mime];
        }

        if (in_array($mime, ['application/octet-stream', 'inode/x-empty'], true)) {
            return $this->sniffExtensionFromContents($file);
        }

        throw new InvalidArgumentException('Invalid file type. MIME type is not permitted.');
    }

    private function sniffExtensionFromContents(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new InvalidArgumentException('Invalid file upload.');
        }

        $sample = file_get_contents($path, false, null, 0, 512);

        if ($sample === false) {
            throw new InvalidArgumentException('Unable to read uploaded file.');
        }

        $trimmed = ltrim($sample);

        if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            return 'json';
        }

        return 'csv';
    }
}
