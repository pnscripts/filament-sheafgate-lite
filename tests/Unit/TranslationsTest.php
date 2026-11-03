<?php

use Illuminate\Support\Arr;

it('has every English text in Bulgarian, with the same placeholders', function () {
    $en = Arr::dot(require __DIR__ . '/../../resources/lang/en/import.php');
    $bg = Arr::dot(require __DIR__ . '/../../resources/lang/bg/import.php');

    expect(array_keys($bg))->toBe(array_keys($en));

    foreach ($en as $key => $text) {
        preg_match_all('/:([a-z_]+)/', $text, $enPlaceholders);
        preg_match_all('/:([a-z_]+)/', $bg[$key], $bgPlaceholders);

        expect(array_unique($bgPlaceholders[1]))->toEqualCanonicalizing(array_unique($enPlaceholders[1]), $key);
        expect($bg[$key])->not->toBe($text, $key);
    }
});

it('speaks Bulgarian when the app does', function () {
    app()->setLocale('bg');

    expect(__('sheafgate-lite::import.sheet.label'))->toBe('Лист')
        ->and(__('sheafgate-lite::import.column', ['letter' => 'C']))->toBe('Колона C')
        ->and(__('sheafgate-lite::import.errors.legacy_xls'))->toContain('.xlsx');
});
