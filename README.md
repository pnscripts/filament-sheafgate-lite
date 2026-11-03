# Sheafgate Lite by PN Scripts

**XLSX, ODS and CSV import for Filament 5, through Filament's own importer.** Swap one `use` line and the Import button of your table accepts Excel and LibreOffice workbooks and CSV files in any common encoding. Filament's column mapping, validation, queued chunks, notifications and failed-rows download work exactly as before.

![The import modal with a workbook: sheet “Spring update”, header in row 3, each column mapped with an example value](docs/images/modal.jpg)

Filament 5's `ImportAction` reads CSV only, and its encoding detection never reaches Windows-1251/1252 or UTF-16. Sheafgate Lite reads the file, turns the chosen sheet into the clean UTF-8 CSV Filament expects, and hands it over. Your importer classes do not change.

## Quick start

1. Install the package:

   ```bash
   composer require pnscripts/sheafgate-lite
   ```

2. Use Sheafgate's action instead of Filament's, with the importer you already have:

   ```php
   use PNScripts\SheafgateLite\ImportAction; // was: use Filament\Actions\ImportAction;

   protected function getHeaderActions(): array
   {
       return [
           ImportAction::make()->importer(ProductImporter::class),
       ];
   }
   ```

3. Click **Import products** and upload an `.xlsx`, `.ods` or `.csv` file.

If you have not used Filament's importer yet, follow Filament's [import action guide](https://filamentphp.com/docs/5.x/actions/import) first (an importer class and the `imports`, `failed_import_rows` and `job_batches` tables). There is nothing else to publish or register.

## What it does

| | |
|---|---|
| ![A Windows-1252 CSV separated by semicolons: the modal says how it was read and shows “Crème Linen Apron”](docs/images/csv.jpg) | ![After the import: Filament's notification with the count and the failed-rows download](docs/images/result.jpg) |
| CSV in UTF-8, UTF-16, Windows-1251 or Windows-1252, with commas, semicolons, tabs or vertical bars: detected and shown. | Filament's own import runs: chunks, notifications and the failed-rows file. |

- **Workbooks:** `.xlsx` (Excel, LibreOffice, Google Sheets exports) and `.ods`. When a workbook has several sheets, a **Sheet** field appears; the first sheet with data is chosen.
- **Header row found for you:** title rows, notes and blank rows above the table are skipped; the **Header row** field lets people correct it.
- **Messy headers handled:** blank header cells become "Column C", merged cells "Size", "Size (2)", duplicates are numbered, line breaks inside names are folded. Filament would refuse duplicate or blank headers.
- **Values as people see them:** dates and date-times (Excel's 1900 and 1904 date systems) as `2026-03-01` and `2026-03-01 14:30:00`, times as `09:30:00`, booleans as `1`/`0`, formulas as their saved result, numbers without float noise or E notation (`0.1 + 0.2` is `0.3`, a 20-digit number is not `1.2E+19`), leading zeros in text cells kept (`00123`).
- **Mapping with examples:** each file column in the mapping shows its first value, for example "Price (e.g. 19.9)".
- **Clear errors:** old `.xls` files ("save it as .xlsx"), damaged workbooks, ZIP files that are not workbooks and empty sheets are refused with a message instead of a stack trace.
- **Everything else is Filament's:** `maxRows()`, `chunkSize()`, `options()`, `job()`, the example CSV, `authorize()`, the completion notification and the failed-rows download behave as documented by Filament.
- **English and Bulgarian** included.

![The same modal in dark mode](docs/images/dark.jpg)

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- Filament 5.8 or newer
- `openspout/openspout` 4.26 or newer, which Filament 5 already installs

Tested with the full test suite (87 tests) on:

| PHP | Laravel | Filament | Livewire | OpenSpout |
|---|---|---|---|---|
| 8.4.27 | 13.40.0 | 5.11.1 | 4.4.8 | 4.32.0 |
| 8.2.35 | 12.71.0 | 5.8.4 | 4.4.8 | 4.28.5 |

The release archive was installed in fresh Laravel apps (Laravel 13 with Filament 5.11 on PHP 8.4, and Laravel 12 with Filament 5.8 on PHP 8.2) and the flow above passed in headless Chrome. The screenshots come from that app.

## Usage

### Options

```php
ImportAction::make()
    ->importer(ProductImporter::class)
    ->headerOffset(2)              // the header is in row 3 (otherwise it is found automatically)
    ->headerRowField(false)        // hide the "Header row" field
    ->csvEncoding('Windows-1251')  // when you know it (otherwise detected)
    ->csvDelimiter(';')            // when you know it (otherwise detected)
```

`ImportAction` extends Filament's `ImportAction`; every Filament option keeps working. The sheet and header row chosen in the modal reach your importer in `$this->options['sheafgate']`.

### How values arrive in your importer

Filament importers receive text. Sheafgate Lite writes cells the way a person reads them: numbers with a dot as the decimal separator and at most 15 significant digits (as spreadsheets show them), percentages as fractions (`15 %` is `0.15`), booleans as `1` and `0`, which `ImportColumn::boolean()` and integer columns both accept. Text cells are passed exactly as written. Cells to the right of the last header are ignored.

### Translations

```bash
php artisan vendor:publish --tag=sheafgate-lite-translations
```

## Why OpenSpout and an own ODS reader

XLSX files are read with [OpenSpout](https://github.com/openspout/openspout) (MIT licence): it streams rows instead of loading the workbook, and Filament 5 already depends on it, so Sheafgate Lite adds no new package. ODS files are read by a small streaming reader in this package, because OpenSpout drops merged ("covered") ODS cells, which shifts later columns, and reads LibreOffice's `false` booleans as true. The tests read real files saved by LibreOffice 24.2.

## Not included

Lite imports what Filament's importer accepts. It has no review before saving, no fixing of rows, no matching by a chosen key column, no lookups with suggestions, no saved mappings, no XLSX template and no import history. Those are the parts of **Sheafgate Pro**.

**Pro — Coming soon:** the full edition, https://pnscripts.com/marketplace/filament-sheafgate.

Also not included: old `.xls` files, password-protected workbooks, and locale-aware numbers (a decimal comma such as `12,50` reaches Filament as written).

## FAQ

**Do I have to change my importer?**
No. Mapping guesses, rules, `resolveRecord()`, hooks and notifications are used as they are.

**Are large files read into memory?**
Workbooks are streamed row by row. Filament itself then splits the rows into queued chunks, as with CSV.

**Does it work with files on S3?**
Uploads on a cloud disk are copied to a temporary local file once per request and read from there.

## Development

```bash
composer install
composer test      # Pest, 87 tests
composer analyse   # PHPStan (Larastan) at the max level
tools/matrix.sh php8.2 "~5.8.0" "^12.0" f58-l12   # the suite on another PHP/Filament/Laravel pair
```

## Licence

MIT. See [LICENSE](LICENSE).

## About

Made by PN Scripts (https://pnscripts.com, office@pnscripts.com). Questions and bug reports: [GitHub issues](https://github.com/pnscripts/filament-sheafgate-lite/issues) or office@pnscripts.com.

Created with AI assistance; the code, tests and texts were checked by PN Scripts with the Pest suite on the PHP, Laravel and Filament versions listed above, PHPStan at the max level, files saved by LibreOffice, and installs of the release archive in fresh Laravel apps used in headless Chrome.
