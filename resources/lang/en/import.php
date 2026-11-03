<?php

return [

    'file' => [
        'placeholder' => 'Upload an XLSX, ODS or CSV file',
        'type' => 'Upload an XLSX, ODS or CSV file.',
        'read_csv' => 'Read as :encoding text, columns separated by :delimiter.',
        'read_sheet' => 'Sheet “:sheet”, column names in row :row.',
        'read_csv_header' => 'Column names in row :row.',
    ],

    'sheet' => [
        'label' => 'Sheet',
    ],

    'header_row' => [
        'label' => 'Header row',
        'helper' => 'The row that holds the column names. Found automatically; change it if your table starts lower.',
    ],

    'columns' => [
        'option' => ':header (e.g. :example)',
    ],

    'column' => 'Column :letter',

    'delimiters' => [
        'comma' => 'commas',
        'semicolon' => 'semicolons',
        'tab' => 'tabs',
        'pipe' => 'vertical bars',
    ],

    'errors' => [
        'unreadable' => 'This file could not be read. Save it as XLSX, ODS or CSV and upload it again.',
        'not_spreadsheet' => 'This file is a ZIP archive, not an XLSX or ODS workbook.',
        'legacy_xls' => 'Excel 97-2003 files (.xls) cannot be read. Open the file and save it as .xlsx.',
        'empty' => 'The sheet is empty.',
        'no_header' => 'The chosen header row is empty. Pick the row that holds the column names.',
        'encoding' => 'The text of this file could not be converted to UTF-8. Save it as UTF-8 CSV and upload it again.',
        'too_large' => 'This file is too large to read safely: it unpacks to more than :size MB. Split it into smaller files.',
        'too_many_rows' => 'The sheet has more than :max rows. Split it into smaller files.',
        'formula_cell' => 'Cell :cell (column “:column”) holds a formula without a saved result, or one that ends in an error. Open the file in Excel or LibreOffice, fix the formula, save it and upload it again.',
        'error_cell' => 'Cell :cell (column “:column”) shows the error :error. Fix it in the spreadsheet and upload the file again.',
        'failed_title' => 'The file could not be imported',
    ],

];
