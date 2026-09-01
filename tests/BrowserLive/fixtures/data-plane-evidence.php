<?php

declare(strict_types=1);

// CLI-only, read-only evidence query for the disposable PostgreSQL rehearsal.
function rejectEvidenceInput(string $stage, int $line): never
{
    fwrite(STDERR, json_encode([
        'stage' => $stage,
        'exception' => 'None',
        'file' => basename(__FILE__),
        'line' => $line,
    ], JSON_THROW_ON_ERROR));
    exit(2);
}

$dsn = getenv('GOFORMX_EVIDENCE_DSN');
$databaseUser = getenv('GOFORMX_EVIDENCE_USER');
$databasePassword = getenv('GOFORMX_EVIDENCE_PASSWORD');
if (PHP_SAPI !== 'cli' || getenv('GOFORMX_BROWSER_REHEARSAL') !== '1' || getenv('APP_ENV') !== 'local'
    || !is_string($dsn) || $dsn === '' || !is_string($databaseUser) || $databaseUser === ''
    || !is_string($databasePassword) || $databasePassword === '') {
    rejectEvidenceInput('guard', __LINE__);
}

$stage = 'input';
try {
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $organizationId = is_string($input['organizationId'] ?? null) ? $input['organizationId'] : '';
    $formId = is_string($input['formId'] ?? null) ? $input['formId'] : '';
    $tokenName = is_string($input['tokenName'] ?? null) ? $input['tokenName'] : '';
    $tokenPlaintext = is_string($input['tokenPlaintext'] ?? null) ? $input['tokenPlaintext'] : '';
    $webhookSecrets = is_array($input['webhookSecrets'] ?? null) ? $input['webhookSecrets'] : [];
    $uuid = '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i';
    if (!preg_match($uuid, $organizationId) || !preg_match($uuid, $formId) || !preg_match('/^browser-token-[0-9a-f-]{36}$/', $tokenName)
        || ($tokenPlaintext !== '' && preg_match('/^gfst_[A-Za-z0-9_-]{43}$/', $tokenPlaintext) !== 1)
        || count($webhookSecrets) > 4) {
        rejectEvidenceInput('input', __LINE__);
    }
    foreach ($webhookSecrets as $secret) {
        if (!is_string($secret) || mb_strlen($secret) < 32 || mb_strlen($secret) > 256) {
            rejectEvidenceInput('input', __LINE__);
        }
    }

    $stage = 'connect';
    $database = new PDO(
        $dsn,
        $databaseUser,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $database->exec("SET default_transaction_read_only = on");

    $stage = 'named-token-counts';
    $token = $database->prepare(<<<'SQL'
        SELECT count(*) FILTER (WHERE revoked_at IS NULL) AS active_count,
               count(*) FILTER (WHERE revoked_at IS NOT NULL) AS revoked_count
        FROM service_tokens WHERE organization_id = :organization AND name = :name
        SQL);
    $token->execute(['organization' => $organizationId, 'name' => $tokenName]);
    $tokenCounts = $token->fetch();

    $organizationToken = $database->prepare(<<<'SQL'
        SELECT count(*) FILTER (WHERE revoked_at IS NULL) AS active_count,
               count(*) FILTER (WHERE revoked_at IS NOT NULL) AS revoked_count
        FROM service_tokens WHERE organization_id = :organization
        SQL);
    $organizationToken->execute(['organization' => $organizationId]);
    $organizationTokenCounts = $organizationToken->fetch();

    $plaintextTokenMatches = 0;
    if ($tokenPlaintext !== '') {
        $stage = 'token-plaintext';
        $plaintextToken = $database->prepare(<<<'SQL'
            SELECT count(*) FROM service_tokens
            WHERE organization_id = :organization
              AND (position(convert_to(:plaintext_bytes, 'UTF8') in token_hash) > 0
                OR position(:plaintext_token_id in token_id) > 0
                OR position(:plaintext_name in name) > 0
                OR position(:plaintext_scopes in scopes::text) > 0)
            SQL);
        $plaintextToken->execute([
            'organization' => $organizationId, 'plaintext_bytes' => $tokenPlaintext,
            'plaintext_token_id' => $tokenPlaintext, 'plaintext_name' => $tokenPlaintext,
            'plaintext_scopes' => $tokenPlaintext,
        ]);
        $plaintextTokenMatches = $plaintextToken->fetchColumn();
    }

    $stage = 'endpoint-count';
    $endpoint = $database->prepare('SELECT count(*) FROM webhook_endpoints WHERE form_id = :form');
    $endpoint->execute(['form' => $formId]);
    $endpointCount = $endpoint->fetchColumn();
    if ($endpointCount === false) {
        throw new RuntimeException('Evidence count unavailable.');
    }

    $stage = 'webhook-plaintext';
    $webhookConfigRows = $database->prepare(<<<'SQL'
        SELECT count(*) FROM (
            SELECT encrypted_config FROM webhook_endpoints WHERE form_id = :endpoint_form
            UNION ALL
            SELECT encrypted_config FROM webhook_deliveries WHERE form_id = :delivery_form
        ) AS configs
        SQL);
    $webhookConfigRows->execute(['endpoint_form' => $formId, 'delivery_form' => $formId]);
    $webhookConfigRowsScanned = $webhookConfigRows->fetchColumn();
    if ($webhookConfigRowsScanned === false) {
        throw new RuntimeException('Evidence count unavailable.');
    }
    $plaintextWebhookConfigMatches = 0;
    $plaintextWebhook = $database->prepare(<<<'SQL'
        SELECT count(*) FROM (
            SELECT encrypted_config FROM webhook_endpoints WHERE form_id = :endpoint_form
            UNION ALL
            SELECT encrypted_config FROM webhook_deliveries WHERE form_id = :delivery_form
        ) AS configs
        WHERE position(convert_to(:plaintext, 'UTF8') in encrypted_config) > 0
        SQL);
    foreach ($webhookSecrets as $secret) {
        $plaintextWebhook->execute(['endpoint_form' => $formId, 'delivery_form' => $formId, 'plaintext' => $secret]);
        $matches = $plaintextWebhook->fetchColumn();
        if ($matches === false) {
            throw new RuntimeException('Evidence count unavailable.');
        }
        $plaintextWebhookConfigMatches += (int) $matches;
    }

    $stage = 'delivery-snapshots';
    $deliveryQuery = $database->prepare(<<<'SQL'
        SELECT uuid, submission_id, endpoint_id, destination_origin, status, attempt_count,
               last_http_status, octet_length(encrypted_config) > 0 AS has_encrypted_config
        FROM webhook_deliveries
        WHERE form_id = :form
        ORDER BY created_at, uuid
        SQL);
    $deliveryQuery->execute(['form' => $formId]);
    $deliveries = array_map(
        static fn(array $row): array => [
            'id' => $row['uuid'],
            'submissionId' => $row['submission_id'],
            'endpointId' => $row['endpoint_id'],
            'origin' => $row['destination_origin'],
            'status' => $row['status'],
            'attemptCount' => (int) $row['attempt_count'],
            'lastHttpStatus' => $row['last_http_status'] === null ? null : (int) $row['last_http_status'],
            'hasEncryptedConfig' => filter_var($row['has_encrypted_config'], FILTER_VALIDATE_BOOL),
        ],
        $deliveryQuery->fetchAll(),
    );

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
        'organizationActiveTokenCount' => (int) $organizationTokenCounts['active_count'],
        'organizationRevokedTokenCount' => (int) $organizationTokenCounts['revoked_count'],
        'plaintextTokenMatches' => (int) $plaintextTokenMatches,
        'plaintextWebhookConfigMatches' => $plaintextWebhookConfigMatches,
        'webhookConfigRowsScanned' => (int) $webhookConfigRowsScanned,
        'webhookEndpointCount' => (int) $endpointCount,
        'deliveries' => $deliveries,
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
