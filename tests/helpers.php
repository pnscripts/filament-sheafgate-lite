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

/**
 * A ZIP archive whose directory states a false (tiny) unpacked size for one entry, as a
 * crafted "zip bomb" does.
 *
 * @param  array<string, string>  $entries
 */
function lyingZip(array $entries, string $liar, int $statedSize = 1000, string $suffix = '.xlsx'): string
{
    $path = zipWith($entries, $suffix);
    $bytes = (string) file_get_contents($path);
    $name = $liar;

    foreach (["PK\x03\x04" => [22, 26], "PK\x01\x02" => [24, 28]] as $signature => [$sizeOffset, $nameLengthOffset]) {
        $offset = 0;

        while (($offset = strpos($bytes, $signature, $offset)) !== false) {
            $nameLength = unpack('v', substr($bytes, $offset + $nameLengthOffset, 2))[1];
            $nameStart = $offset + ($signature === "PK\x03\x04" ? 30 : 46);

            if (substr($bytes, $nameStart, $nameLength) === $name) {
                $bytes = substr_replace($bytes, pack('V', $statedSize), $offset + $sizeOffset, 4);
            }

            $offset += 4;
        }
    }

    file_put_contents($path, $bytes);

    return $path;
}

/**
 * A minimal XLSX package around the given sheet XML (and optional shared strings).
 */
function xlsxWithSheet(string $sheetXml, ?string $sharedStrings = null): array
{
    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . ($sharedStrings !== null ? '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' : '') . '</Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' . ($sharedStrings !== null ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' : '') . '</Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetXml . '</sheetData></worksheet>',
    ];

    if ($sharedStrings !== null) {
        $entries['xl/sharedStrings.xml'] = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $sharedStrings . '</sst>';
    }

    return $entries;
}
