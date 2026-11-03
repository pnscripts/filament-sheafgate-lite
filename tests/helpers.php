<?php

use Illuminate\Http\UploadedFile;

/**
 * A fixture file from tests/Fixtures/files as an upload.
 */
function fixtureUpload(string $name, ?string $as = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($as ?? $name, (string) file_get_contents(fixturePath($name)));
}

function fixturePath(string $name): string
{
    return __DIR__ . '/Fixtures/files/' . $name;
}

/**
 * Raw bytes as an upload (CSV tests build their own bytes).
 */
function bytesUpload(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * Writes bytes to a temporary file and returns its path.
 */
function tempFileWith(string $bytes, string $suffix = '.csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-test-') . $suffix;
    file_put_contents($path, $bytes);

    return $path;
}
