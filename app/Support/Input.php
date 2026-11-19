<?php

declare(strict_types=1);

namespace BarberLead\Support;

final class Input
{
    /** Trimmed scalar value from form input, '' when missing or not scalar. @param array<string,mixed> $in */
    public static function str(array $in, string $key): string
    {
        $v = $in[$key] ?? null;
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
