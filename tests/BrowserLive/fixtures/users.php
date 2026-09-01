<?php

declare(strict_types=1);

// CLI-only, disposable-database fixture. Never route or deploy this as an endpoint.
if (PHP_SAPI !== 'cli' || getenv('GOFORMX_BROWSER_REHEARSAL') !== '1' || getenv('APP_ENV') !== 'local') {
    exit(2);
}
$root = dirname(__DIR__, 3);
$database = getenv('WAASEYAA_DB');
if (!$database || !is_file($database) || realpath($database) === realpath($root . '/storage/waaseyaa.sqlite')) {
    exit(2);
}
require $root . '/vendor/autoload.php';

use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;

$stage = 'boot';
try {
    $kernel = new HttpKernel($root);
    $kernel->bootForCli();
    $kernel->accountContext()->set(new DevAdminAccount());
    $manager = $kernel->getEntityTypeManager();
    $users = $manager->getRepository('user');
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if ($input['action'] === 'create') {
        $stage = 'create';
        $result = [];
        foreach (['Owner', 'Foreign'] as $name) {
            $email = 'browser-ui-' . bin2hex(random_bytes(10)) . '@example.test';
            $password = bin2hex(random_bytes(24));
            $user = new User(['name' => 'Browser UI ' . $name, 'mail' => $email,
                'pass' => password_hash($password, PASSWORD_DEFAULT), 'status' => 1,
                'roles' => ['authenticated'], 'created' => time()]);
            $user->setEmailVerified(true);
            $users->save($user);
            $result[] = ['id' => (int) $user->id(), 'subject' => $user->uuid(), 'email' => $email, 'password' => $password];
        }
        // Private pipe only; the test must not print credentials or retain browser storage.
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
    } elseif (in_array($input['action'], ['grant-membership', 'change-membership', 'delete-account'], true)) {
        $internalFields = $kernel->getHttpServiceResolver()->resolve(UserInternalFieldReaderInterface::class);
        if (!$internalFields instanceof UserInternalFieldReaderInterface) throw new RuntimeException('Fixture identity reader unavailable.');
        $fixture = $input['user'] ?? [];
        $user = $users->find((string) ($fixture['id'] ?? ''));
        if (!$user instanceof User || !is_string($fixture['subject'] ?? null)
            || !hash_equals($user->uuid(), $fixture['subject'])
            || !str_starts_with($internalFields->sessionIdentity($user)->mail, 'browser-ui-')) {
            throw new RuntimeException('Fixture identity mismatch.');
        }
        $organizationId = is_string($input['organizationId'] ?? null) ? $input['organizationId'] : '';
        if (preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $organizationId) !== 1) {
            throw new RuntimeException('Fixture organization mismatch.');
        }
        $memberships = $manager->getRepository('goformx_organization_membership');
        $membershipIds = $memberships->getQuery()->accessCheck(false)
            ->condition('user_id', (int) $user->id())->condition('organization_uuid', $organizationId)->execute();
        $matching = array_values($memberships->findMany($membershipIds));
        if ($input['action'] === 'grant-membership') {
            if ($matching !== [] || ($input['role'] ?? null) !== 'member') throw new RuntimeException('Fixture grant mismatch.');
            $organizationIds = $manager->getRepository('goformx_organization')->getQuery()->accessCheck(false)
                ->condition('uuid', $organizationId)->execute();
            if (count($organizationIds) !== 1) throw new RuntimeException('Fixture organization unavailable.');
            $membership = $memberships->create(['organization_uuid' => $organizationId, 'user_id' => (int) $user->id(),
                'role' => 'member', 'status' => 'active', 'joined_at' => time()]);
            $memberships->save($membership);
        } elseif ($input['action'] === 'change-membership') {
            $role = $input['role'] ?? null;
            $status = $input['status'] ?? null;
            if (count($matching) !== 1 || !in_array($role, ['member', 'admin'], true)
                || !in_array($status, ['active', 'revoked'], true)) throw new RuntimeException('Fixture membership mismatch.');
            $matching[0]->set('role', $role);
            $matching[0]->set('status', $status);
            $memberships->save($matching[0]);
        } else {
            if (($input['confirm'] ?? null) !== 'delete-disposable-browser-account') throw new RuntimeException('Fixture deletion mismatch.');
            $allIds = $memberships->getQuery()->accessCheck(false)->condition('user_id', (int) $user->id())->execute();
            foreach ($memberships->findMany($allIds) as $membership) {
                $organizations = $manager->getRepository('goformx_organization');
                $organizationIds = $organizations->getQuery()->accessCheck(false)
                    ->condition('uuid', (string) $membership->get('organization_uuid'))->execute();
                $memberships->delete($membership);
                foreach ($organizations->findMany($organizationIds) as $organization) {
                    if ((int) $organization->get('created_by_user_id') === (int) $user->id()) $organizations->delete($organization);
                }
            }
            $users->delete($user);
        }
        fwrite(STDOUT, '{"ok":true}');
    } elseif ($input['action'] === 'cleanup') {
        $internalFields = $kernel->getHttpServiceResolver()->resolve(UserInternalFieldReaderInterface::class);
        if (!$internalFields instanceof UserInternalFieldReaderInterface) throw new RuntimeException('Fixture identity reader unavailable.');
        foreach ($input['users'] as $fixture) {
            $stage = 'load-user';
            $user = $users->find((string) $fixture['id']);
            if (!$user instanceof User && ($fixture['allowMissing'] ?? false) === true) continue;
            if (!$user instanceof User || !hash_equals($user->uuid(), $fixture['subject']) || !str_starts_with($internalFields->sessionIdentity($user)->mail, 'browser-ui-')) {
                throw new RuntimeException('Fixture identity mismatch.');
            }
            $memberships = $manager->getRepository('goformx_organization_membership');
            $stage = 'find-memberships';
            $ids = $memberships->getQuery()->accessCheck(false)->condition('user_id', (int) $user->id())->execute();
            foreach ($memberships->findMany($ids) as $membership) {
                $organizations = $manager->getRepository('goformx_organization');
                $stage = 'find-organizations';
                $organizationIds = $organizations->getQuery()->accessCheck(false)->condition('uuid', (string) $membership->get('organization_uuid'))->execute();
                $stage = 'delete-membership';
                $memberships->delete($membership);
                foreach ($organizations->findMany($organizationIds) as $organization) {
                    $stage = 'delete-organization';
                    if ((int) $organization->get('created_by_user_id') === (int) $user->id()) $organizations->delete($organization);
                }
            }
            $stage = 'delete-user';
            $users->delete($user);
        }
    } else {
        exit(2);
    }
} catch (Throwable $error) {
    // Code location only, never exception messages, SQL, arguments or identity data.
    fwrite(STDERR, json_encode(['stage' => $stage, 'exception' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR));
    exit(1);
}
