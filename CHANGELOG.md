# Changelog

All notable changes to Sheafgate Lite are listed here. The project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-11-03

First release.

- `ImportAction`, a drop-in replacement for Filament 5's import action that reads XLSX, ODS and CSV/TSV files.
- Filament's column mapping, validation, queued chunks, notifications and failed-rows download work unchanged.
- CSV encodings detected: UTF-8 (with or without BOM), UTF-16 LE/BE, Windows-1251 and Windows-1252; delimiters: comma, semicolon, tab, vertical bar.
- Sheet choice for workbooks with several sheets; header row found below title rows; merged, blank and duplicate header cells named.
- Spreadsheet values as people see them: dates (1900 and 1904 systems), times, booleans, formulas as their saved results, numbers without float noise, leading zeros kept.
- The mapping shows an example value next to each file column.
- Clear messages for old .xls files, damaged workbooks and empty sheets.
- English and Bulgarian translations.
