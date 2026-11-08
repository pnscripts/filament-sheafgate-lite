<?php

namespace PNScripts\SheafgateLite;

use Closure;
use Filament\Actions\ImportAction as FilamentImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader as CsvReader;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;
use PNScripts\SheafgateLite\Reader\Table;

/**
 * Filament's ImportAction, reading XLSX, ODS and CSV files.
 *
 * The file is read here and handed to Filament as a clean UTF-8 CSV, so the column
 * mapping, validation, queued chunks, notifications and the failed-rows download of
 * Filament's own importer work unchanged.
 */
class ImportAction extends FilamentImportAction
{
    /** @var list<string> */
    public const EXTENSIONS = ['xlsx', 'ods', 'csv', 'tsv', 'txt'];

    /** @var list<string> accepted so the reader can explain how to convert them */
    public const EXPLAINED_EXTENSIONS = ['xls'];

    /** @var list<string> */
    public const MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
        'text/x-csv',
        'application/csv',
        'application/x-csv',
        'text/comma-separated-values',
        'text/x-comma-separated-values',
        'text/tab-separated-values',
        'text/plain',
        'application/vnd.ms-excel',
        // Some systems report workbooks as plain ZIP or binary data; the content is checked when read.
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];

    protected string | Closure | null $csvEncoding = null;

    protected bool | Closure $showsHeaderRowField = true;

    /** @var array<string, SpreadsheetReader> */
    protected array $readers = [];

    /** @var array<string, Table> */
    protected array $tables = [];

    /** @var array<string, string> local copies of uploads kept on cloud disks, by upload name */
    protected static array $localCopies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(fn (ImportAction $action): array => array_merge([
            $action->getFileComponent(),
            $action->getSheetComponent(),
            $action->getHeaderRowComponent(),
            $action->getColumnMapComponent(),
        ], $action->getImporter()::getOptionsFormComponents()));
    }

    /**
     * The encoding of uploaded CSV files, when you know it (detected otherwise).
     */
    public function csvEncoding(string | Closure | null $encoding): static
    {
        $this->csvEncoding = $encoding;

        return $this;
    }

    /**
     * Filament 5.11 passes the open CSV stream, so a closure can look at the file; older
     * releases call this without it.
     *
     * @param  resource | null  $resource
     */
    public function getCsvEncoding(mixed $resource = null): ?string
    {
        return $this->toText($this->evaluate($this->csvEncoding, [
            'resource' => $resource,
        ]));
    }

    /**
     * Show or hide the "Header row" field (the header row is still detected).
     */
    public function headerRowField(bool | Closure $condition = true): static
    {
        $this->showsHeaderRowField = $condition;

        return $this;
    }

    public function showsHeaderRowField(): bool
    {
        return (bool) $this->evaluate($this->showsHeaderRowField);
    }

    /**
     * Filament reads the converted file, which always has its header in the first row.
     * The offset you set with headerOffset() picks the header row of the uploaded file.
     */
    public function getHeaderOffset(): ?int
    {
        return 0;
    }

    /**
     * Filament reads the converted file, which is always comma-separated. The delimiter
     * you set with csvDelimiter() applies to uploaded CSV files and the example file.
     *
     * @param  CsvReader<array<mixed>> | null  $reader
     */
    public function getCsvDelimiter(?CsvReader $reader = null): ?string
    {
        if ($reader !== null) {
            return ',';
        }

        return $this->toText($this->evaluate($this->csvDelimiter));
    }

    /**
     * The uploaded file as the UTF-8 CSV stream Filament's importer reads.
     *
     * @return resource | false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        $choice = $this->getSpreadsheetChoice();

        try {
            $stream = $this->readTable($file, $choice['sheet'], $choice['header_row'])->toCsvStream();

            // The import has its rows now: cached previews of the upload are not kept.
            SpreadsheetReader::forget($this->getLocalPath($file));

            return $stream;
        } catch (UnreadableSpreadsheet $exception) {
            Notification::make()
                ->title(__('sheafgate-lite::import.errors.failed_title'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }

    /**
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        $rules = [
            'extensions:' . implode(',', array_merge(self::EXTENSIONS, self::EXPLAINED_EXTENSIONS)),
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof TemporaryUploadedFile) {
                    return;
                }

                try {
                    $reader = $this->getSpreadsheetReader($value);

                    if ($reader->preview($reader->firstSheetWithData(), 1) === []) {
                        $fail(__('sheafgate-lite::import.errors.empty'));
                    }
                } catch (UnreadableSpreadsheet $exception) {
                    $fail($exception->getMessage());
                }
            },
        ];

        foreach ($this->fileValidationRules as $extraRules) {
            $extraRules = $this->evaluate($extraRules);

            if (is_string($extraRules)) {
                $extraRules = explode('|', $extraRules);
            }

            $rules = array_merge($rules, is_array($extraRules) ? $extraRules : [$extraRules]);
        }

        return $rules;
    }

    public function getSpreadsheetReader(TemporaryUploadedFile $file): SpreadsheetReader
    {
        $path = $this->getLocalPath($file);

        if (! isset($this->readers[$path])) {
            $reader = SpreadsheetReader::open($path, $file->getClientOriginalName(), $this->getCsvEncoding(), $this->getCsvDelimiter());

            // One row past maxRows() so Filament can refuse the file with its own message.
            $maxRows = $this->getMaxRows();
            $reader->rowLimit = $maxRows !== null ? min($maxRows + 1, SpreadsheetReader::$maxRows) : null;

            $this->readers[$path] = $reader;
        }

        return $this->readers[$path];
    }

    public function readTable(TemporaryUploadedFile $file, ?string $sheet = null, ?int $headerRow = null): Table
    {
        $reader = $this->getSpreadsheetReader($file);
        $headerRow ??= $this->getConfiguredHeaderRow();
        $key = $reader->path . '|' . $sheet . '|' . $headerRow;

        return $this->tables[$key] ??= $reader->table($sheet, $headerRow, $this->getColumnGuesses());
    }

    /**
     * The sheet and header row chosen in the modal.
     *
     * @return array{sheet: ?string, header_row: ?int}
     */
    public function getSpreadsheetChoice(): array
    {
        $choice = $this->getRawData()['sheafgate'] ?? [];
        $choice = is_array($choice) ? $choice : [];
        $headerRow = $choice['header_row'] ?? null;

        return [
            'sheet' => $this->toText($choice['sheet'] ?? null),
            'header_row' => $this->toHeaderRow($headerRow),
        ];
    }

    /**
     * Lower-case guesses per importer column, used to find the header row.
     *
     * @return array<string, list<string>>
     */
    public function getColumnGuesses(): array
    {
        $guesses = [];

        foreach ($this->getImporter()::getColumns() as $column) {
            $guesses[$column->getName()] = array_values($column->getGuesses());
        }

        return $guesses;
    }

    /**
     * The same guessing Filament does, against the headers of the chosen sheet.
     *
     * @param  list<string>  $headers
     * @return array<string, ?string>
     */
    public function guessColumnMap(array $headers): array
    {
        $lowercase = array_map(Str::lower(...), $headers);
        $byLowercase = array_combine($lowercase, $headers);
        $map = [];

        foreach ($this->getImporter()::getColumns() as $column) {
            $match = array_values(array_intersect($lowercase, $column->getGuesses()))[0] ?? null;
            $map[$column->getName()] = $match !== null ? $byLowercase[$match] : null;
        }

        return $map;
    }

    protected function getFileComponent(): FileUpload
    {
        return FileUpload::make('file')
            ->label(__('filament-actions::import.modal.form.file.label'))
            ->placeholder(__('sheafgate-lite::import.file.placeholder'))
            ->acceptedFileTypes(self::MIME_TYPES)
            ->rules($this->getFileValidationRules())
            ->validationMessages([
                'mimetypes' => __('sheafgate-lite::import.file.type'),
                'extensions' => __('sheafgate-lite::import.file.type'),
            ])
            ->afterStateUpdated(function (FileUpload $component, Component $livewire, Set $set, mixed $state): void {
                if (! $state instanceof TemporaryUploadedFile) {
                    return;
                }

                try {
                    $livewire->validateOnly($component->getStatePath());
                } catch (ValidationException $exception) {
                    $component->state([]);

                    throw $exception;
                }

                $sheet = $this->getSpreadsheetReader($state)->firstSheetWithData();
                $set('sheafgate.sheet', $sheet);
                $this->refreshTable($state, $sheet, null, $set);
            })
            ->belowContent(function (Get $get): ?string {
                $file = $get('file');

                if (! $file instanceof TemporaryUploadedFile) {
                    return null;
                }

                return $this->describeFile($file, $get('sheafgate.sheet'), $get('sheafgate.header_row'));
            })
            ->storeFiles(false)
            ->visibility('private')
            ->required()
            ->hiddenLabel();
    }

    protected function getSheetComponent(): Select
    {
        return Select::make('sheafgate.sheet')
            ->label(__('sheafgate-lite::import.sheet.label'))
            ->options(function (Get $get): array {
                $file = $get('file');

                if (! $file instanceof TemporaryUploadedFile) {
                    return [];
                }

                try {
                    $sheets = $this->getSpreadsheetReader($file)->sheets();
                } catch (UnreadableSpreadsheet) {
                    return [];
                }

                return array_combine($sheets, $sheets);
            })
            ->selectablePlaceholder(false)
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                $file = $get('file');

                if ($file instanceof TemporaryUploadedFile) {
                    $this->refreshTable($file, $this->toText($state), null, $set);
                }
            })
            ->visible(function (Get $get): bool {
                $file = $get('file');

                if (! $file instanceof TemporaryUploadedFile) {
                    return false;
                }

                try {
                    return count($this->getSpreadsheetReader($file)->sheets()) > 1;
                } catch (UnreadableSpreadsheet) {
                    return false;
                }
            });
    }

    protected function getHeaderRowComponent(): TextInput
    {
        return TextInput::make('sheafgate.header_row')
            ->label(__('sheafgate-lite::import.header_row.label'))
            ->helperText(__('sheafgate-lite::import.header_row.helper'))
            ->integer()
            ->minValue(1)
            ->maxValue(1000000)
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                $file = $get('file');

                if ($file instanceof TemporaryUploadedFile) {
                    $this->refreshTable($file, $this->toText($get('sheafgate.sheet')), $this->toHeaderRow($state), $set, keepHeaderRow: true);
                }
            })
            ->visible(fn (Get $get): bool => $this->showsHeaderRowField() && ($get('file') instanceof TemporaryUploadedFile));
    }

    protected function getColumnMapComponent(): Fieldset
    {
        return Fieldset::make(__('filament-actions::import.modal.form.columns.label'))
            ->columns(1)
            ->inlineLabel()
            ->schema(function (Get $get): array {
                $file = $get('file');

                if (! $file instanceof TemporaryUploadedFile) {
                    return [];
                }

                try {
                    $table = $this->readTable($file, $this->toText($get('sheafgate.sheet')), $this->toHeaderRow($get('sheafgate.header_row')));
                } catch (UnreadableSpreadsheet) {
                    return [];
                }

                $options = $this->getColumnOptions($table);

                return array_map(
                    fn (ImportColumn $column): Select => $column->getSelect()->options($options),
                    $this->getImporter()::getColumns(),
                );
            })
            ->statePath('columnMap')
            ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile);
    }

    /**
     * Header => label, the label showing the first value found below the header.
     *
     * @return array<string, string>
     */
    protected function getColumnOptions(Table $table): array
    {
        $examples = [];

        foreach ($table->sample(10) as $row) {
            foreach ($row as $header => $value) {
                if (! isset($examples[$header]) && trim($value) !== '') {
                    $examples[$header] = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $value)), 24);
                }
            }
        }

        $options = [];

        foreach ($table->headers as $header) {
            $options[$header] = isset($examples[$header])
                ? __('sheafgate-lite::import.columns.option', ['header' => $header, 'example' => $examples[$header]])
                : $header;
        }

        return $options;
    }

    protected function refreshTable(TemporaryUploadedFile $file, ?string $sheet, ?int $headerRow, Set $set, bool $keepHeaderRow = false): void
    {
        try {
            $table = $this->readTable($file, $sheet, $headerRow);
        } catch (UnreadableSpreadsheet) {
            $set('columnMap', []);

            return;
        }

        if (! $keepHeaderRow) {
            $set('sheafgate.header_row', $table->headerRow);
        }

        $set('columnMap', $this->guessColumnMap($table->headers));
    }

    /**
     * The line under the upload field: how the file was read.
     */
    public function describeFile(TemporaryUploadedFile $file, mixed $sheet, mixed $headerRow): ?string
    {
        try {
            $table = $this->readTable($file, $this->toText($sheet), $this->toHeaderRow($headerRow));
        } catch (UnreadableSpreadsheet $exception) {
            return $exception->getMessage();
        }

        $parts = [];

        if ($table->format === 'csv') {
            $parts[] = __('sheafgate-lite::import.file.read_csv', [
                'encoding' => $table->encoding,
                'delimiter' => __('sheafgate-lite::import.delimiters.' . match ($table->delimiter) {
                    ';' => 'semicolon',
                    "\t" => 'tab',
                    '|' => 'pipe',
                    default => 'comma',
                }),
            ]);
            $parts[] = __('sheafgate-lite::import.file.read_csv_header', ['row' => $table->headerRow]);
        } else {
            $parts[] = __('sheafgate-lite::import.file.read_sheet', ['sheet' => $table->sheet, 'row' => $table->headerRow]);
        }

        return implode(' ', $parts);
    }

    protected function getConfiguredHeaderRow(): ?int
    {
        $offset = $this->evaluate($this->headerOffset);

        return is_int($offset) ? $offset + 1 : null;
    }

    protected function toHeaderRow(mixed $value): ?int
    {
        return (is_numeric($value) && (int) $value >= 1) ? (int) $value : null;
    }

    /**
     * A filled string from form state or an evaluated option, or null.
     */
    protected function toText(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * A local path for the upload (copied from cloud disks such as S3 once per request).
     */
    protected function getLocalPath(TemporaryUploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path !== '' && is_file($path)) {
            return $path;
        }

        $key = $file->getFilename();

        if (isset(static::$localCopies[$key]) && is_file(static::$localCopies[$key])) {
            return static::$localCopies[$key];
        }

        $stream = $file->readStream();
        $target = tempnam(sys_get_temp_dir(), 'sheafgate-upload-');

        if (! is_resource($stream) || $target === false) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        $out = fopen($target, 'wb');

        if ($out === false) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        register_shutdown_function(static fn () => @unlink($target));

        return static::$localCopies[$key] = $target;
    }
}
