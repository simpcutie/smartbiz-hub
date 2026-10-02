<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function cents(mixed $value): int
    {
        $value = trim((string) ($value ?? '0'));
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a positive amount with at most two decimal places.']);
        }
        $parts = explode('.', $value);

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function format(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
