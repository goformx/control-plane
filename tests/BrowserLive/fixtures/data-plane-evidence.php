<?php

declare(strict_types=1);

// CLI-only, read-only evidence query for the disposable PostgreSQL rehearsal.
function rejectEvidenceInput(string $stage): never
{
    fwrite(STDERR, json_encode([
        'stage' => $stage,
        'exception' => 'None',
        'file' => basename(__FILE__),
        'line' => __LINE__,
    ], JSON_THROW_ON_ERROR));
    exit(2);
}

$dsn = getenv('GOFORMX_EVIDENCE_DSN');
$databaseUser = getenv('GOFORMX_EVIDENCE_USER');
$databasePassword = getenv('GOFORMX_EVIDENCE_PASSWORD');
if (PHP_SAPI !== 'cli' || getenv('GOFORMX_BROWSER_REHEARSAL') !== '1' || getenv('APP_ENV') !== 'local'
    || !is_string($dsn) || $dsn === '' || !is_string($databaseUser) || $databaseUser === ''
    || !is_string($databasePassword) || $databasePassword === '') {
    rejectEvidenceInput('guard');
}

$stage = 'input';
try {
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $organizationId = is_string($input['organizationId'] ?? null) ? $input['organizationId'] : '';
    $formId = is_string($input['formId'] ?? null) ? $input['formId'] : '';
    $tokenName = is_string($input['tokenName'] ?? null) ? $input['tokenName'] : '';
    $uuid = '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i';
    if (!preg_match($uuid, $organizationId) || !preg_match($uuid, $formId) || !preg_match('/^browser-token-[0-9a-f-]{36}$/', $tokenName)) {
        rejectEvidenceInput('input');
    }

    $stage = 'connect';
    $database = new PDO(
        $dsn,
        $databaseUser,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $database->exec("SET default_transaction_read_only = on");

    $stage = 'token-counts';
    $token = $database->prepare(<<<'SQL'
        SELECT count(*) FILTER (WHERE revoked_at IS NULL) AS active_count,
               count(*) FILTER (WHERE revoked_at IS NOT NULL) AS revoked_count
        FROM service_tokens WHERE organization_id = :organization AND name = :name
        SQL);
    $token->execute(['organization' => $organizationId, 'name' => $tokenName]);
    $tokenCounts = $token->fetch();

    $stage = 'endpoint-count';
    $endpoint = $database->prepare('SELECT count(*) FROM webhook_endpoints WHERE form_id = :form');
    $endpoint->execute(['form' => $formId]);
    $endpointCount = $endpoint->fetchColumn();
    if ($endpointCount === false) {
        throw new RuntimeException('Evidence count unavailable.');
    }

    $stage = 'audits';
    $audits = $database->prepare(<<<'SQL'
        SELECT audit_id, event
        FROM management_audit
        WHERE organization_id = :organization
          AND (form_id = :form OR event LIKE 'service_token.%')
        ORDER BY occurred_at, audit_id
        SQL);
    $audits->execute(['organization' => $organizationId, 'form' => $formId]);
    $events = array_map(
        static fn(array $row): array => ['auditId' => $row['audit_id'], 'event' => $row['event']],
        $audits->fetchAll(),
    );

    fwrite(STDOUT, json_encode([
        'activeTokenCount' => (int) $tokenCounts['active_count'],
        'revokedTokenCount' => (int) $tokenCounts['revoked_count'],
        'webhookEndpointCount' => (int) $endpointCount,
        'events' => $events,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    // Code location only: do not emit SQL, row data, credentials, or exception messages.
    fwrite(STDERR, json_encode([
        'stage' => $stage,
        'exception' => get_class($error),
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
