<?php

declare(strict_types=1);

// CLI-only probe of the real application container. Never register this as a route.
if (PHP_SAPI !== 'cli' || getenv('GOFORMX_CUSTODY_REHEARSAL') !== '1' || getenv('APP_ENV') !== 'local') {
    exit(2);
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Domain\GoFormX\ManagementScope;
use App\Infrastructure\GoFormX\FirstPartyAssertionIssuer;
use Waaseyaa\Foundation\Kernel\HttpKernel;

try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $kernel = new HttpKernel(dirname(__DIR__, 3));
    $kernel->bootForCli();
    $issuer = $kernel->getHttpServiceResolver()->resolve(FirstPartyAssertionIssuer::class);
    $assertion = $issuer->issue(
        $input['subject'],
        $input['organization'],
        array_map(ManagementScope::from(...), $input['scopes']),
    );
    // The parent captures this private pipe, never console output or an artifact.
    fwrite(STDOUT, $assertion->compact);
} catch (Throwable) {
    fwrite(STDERR, "Configured assertion issuance failed; sensitive diagnostics withheld.\n");
    exit(1);
}
