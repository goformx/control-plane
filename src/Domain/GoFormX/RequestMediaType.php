<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

enum RequestMediaType: string
{
    case Json = 'application/json';
    case MergePatch = 'application/merge-patch+json';
}
