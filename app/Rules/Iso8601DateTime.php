<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Клиент присылает время в ISO 8601 с любым смещением — этот формат и проверяем,
 * а не «что угодно, что умеет разобрать Carbon».
 *
 * Принимаемые варианты: 2026-12-01T10:00:00+03:00, 2026-12-01T10:00:00Z,
 * 2026-12-01T10:00:00.123+03:00, 2026-12-01T10:00+0300.
 * Отклоняются: время без смещения, с пробелом вместо T, несуществующие даты.
 */
class Iso8601DateTime implements ValidationRule
{
    private const PATTERN = '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])'
        .'T([01]\d|2[0-3]):[0-5]\d'
        .'(?::[0-5]\d(?:\.\d{1,6})?)?'
        .'(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $parts) !== 1) {
            $fail('Время должно быть в формате ISO 8601 со смещением, например 2026-12-01T10:00:00+03:00.');

            return;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $fail('Такой даты не существует.');
        }
    }
}
