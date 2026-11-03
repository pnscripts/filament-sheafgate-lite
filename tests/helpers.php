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

/**
 * Livewire's own test helper (the Pest plugin needs PHP 8.3 with Livewire 4; this runs on 8.2 too).
 *
 * @param  array<string, mixed>  $params
 */
function livewire(string $component, array $params = []): \Livewire\Features\SupportTesting\Testable
{
    return \Livewire\Livewire::test($component, $params);
}

/**
 * A ZIP archive with the given entries (name => content).
 *
 * @param  array<string, string>  $entries
 */
function zipWith(array $entries, string $suffix = '.xlsx'): string
{
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-zip-') . $suffix;
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}
