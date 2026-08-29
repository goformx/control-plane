<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

enum ManagementScope: string
{
    case FormsRead = 'forms:read';
    case FormsWrite = 'forms:write';
    case FormsPublish = 'forms:publish';
    case SubmissionsRead = 'submissions:read';
    case TokensRead = 'tokens:read';
    case TokensWrite = 'tokens:write';
    case WebhooksRead = 'webhooks:read';
    case WebhooksWrite = 'webhooks:write';
}
