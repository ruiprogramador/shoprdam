<?php

use App\Domain\Payments\ConfigInteger;

it('parses a valid non-negative integer regardless of its native type', function (mixed $value, int $expected) {
    expect(ConfigInteger::parse($value, min: 0))->toBe($expected);
})->with([
    'native int' => [15, 15],
    'string int' => ['15', 15],
    'zero' => [0, 0],
    'string zero' => ['0', 0],
]);

it('rejects a value below the given minimum', function (mixed $value, int $min) {
    expect(ConfigInteger::parse($value, $min))->toBeNull();
})->with([
    'negative below min 0' => [-1, 0],
    'zero below min 1' => [0, 1],
    'below min 1' => ['0', 1],
]);

it('accepts a value exactly at the minimum', function () {
    expect(ConfigInteger::parse(1, min: 1))->toBe(1)
        ->and(ConfigInteger::parse('0', min: 0))->toBe(0);
});

it('never coerces a malformed value — it fails closed with null instead of a truncated/rounded guess', function (mixed $value) {
    expect(ConfigInteger::parse($value, min: 0))->toBeNull();
})->with([
    'non-numeric string' => ['abc'],
    'decimal string' => ['3.5'],
    'empty string' => [''],
    'whitespace string' => ['   '],
    'null' => [null],
    'bool true' => [true],
    'array' => [[1, 2]],
]);

it('prints the original value verbatim for an error message, never the parsed/coerced result', function () {
    expect(ConfigInteger::printable('abc'))->toBe('abc')
        ->and(ConfigInteger::printable(''))->toBe('')
        ->and(ConfigInteger::printable(-1))->toBe('-1')
        ->and(ConfigInteger::printable(null))->toBe('null');
});
