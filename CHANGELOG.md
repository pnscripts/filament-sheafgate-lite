# Changelog

All notable changes to Sheafgate Lite are listed here. The project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-11-03

First release.

- `ImportAction`, a drop-in replacement for Filament 5's import action that reads XLSX, ODS and CSV/TSV files.
- Filament's column mapping, validation, queued chunks, notifications and failed-rows download work unchanged.
- CSV encodings detected: UTF-8 (with or without BOM), UTF-16 LE/BE, Windows-1251 and Windows-1252; delimiters: comma, semicolon, tab, vertical bar.
- Sheet choice for workbooks with several sheets; header row found below title rows; merged, blank and duplicate header cells named.
- Spreadsheet values read faithfully: dates (1900 and 1904 systems), times, booleans, formulas as their saved results, numbers without float noise, leading zeros in text cells kept.
- The mapping shows an example value next to each file column.
- Clear messages for old .xls files, damaged workbooks and empty sheets.
- Safety limits: 50 000 data rows (or `maxRows()`) and real unpacked size counted per workbook part (10 MB or 100x its packed size, 256 MB in total), 32,767 characters per cell, checked before and while reading.
- Formulas without a saved result and error cells (`#DIV/0!`) refuse the file with the cell named, never import 0 or empty.
- English and Bulgarian translations.
