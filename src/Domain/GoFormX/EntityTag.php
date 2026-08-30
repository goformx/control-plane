<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

final class EntityTag
{
    public static function isStrong(string $value): bool
    {
        return preg_match('/\A"[\x21\x23-\x7e]{1,200}"\z/', $value) === 1;
    }
}
