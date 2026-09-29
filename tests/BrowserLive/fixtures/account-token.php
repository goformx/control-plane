<?php

declare(strict_types=1);

// Disposable browser rehearsal only. The token is returned through a private
// subprocess pipe, never an HTTP response, log, fixture file or test artifact.
if (PHP_SAPI !== 'cli' || getenv('GOFORMX_ACCOUNT_REHEARSAL') !== '1' || getenv('APP_ENV') !== 'local') exit(2);
$root = dirname(__DIR__, 3);
$database = getenv('WAASEYAA_DB');
if (!$database || !is_file($database) || realpath($database) === realpath($root . '/storage/waaseyaa.sqlite')) exit(2);
require $root . '/vendor/autoload.php';

use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;

try {
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $email = $input['email'] ?? null;
    $type = $input['type'] ?? null;
    if (!is_string($email) || preg_match('/\Aaccount-gate-[a-f0-9]+@example\.test\z/', $email) !== 1 ||
        !in_array($type, ['email_verification', 'password_reset'], true)) exit(2);
    $kernel = new HttpKernel($root);
    $kernel->bootForCli();
    $kernel->accountContext()->set(new DevAdminAccount());
    $lookup = $kernel->getHttpServiceResolver()->resolve(UserIdentityLookupInterface::class);
    $user = $lookup->findActiveByMail($kernel->getEntityTypeManager()->getRepository('user'), $email);
    $reader = $kernel->getHttpServiceResolver()->resolve(UserInternalFieldReaderInterface::class);
    $mail = $user instanceof User ? $reader->verification($user)->mail : '';
    if (!is_string($mail) || preg_match('/\Aaccount-gate-[a-f0-9]+@example\.test\z/', $mail) !== 1) exit(2);
    $tokens = $kernel->getHttpServiceResolver()->resolve(AuthTokenRepositoryInterface::class);
    fwrite(STDOUT, $tokens->createToken((string) $user->id(), $type, $type === 'email_verification' ? 86400 : 3600));
} catch (Throwable) {
    exit(1);
}
