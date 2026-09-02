<?php

declare(strict_types=1);

// CLI-only writer for the disposable PostgreSQL cross-service rehearsal.
function rejectSeedInput(string $stage, int $line): never
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
    rejectSeedInput('guard', __LINE__);
}

$stage = 'input';
try {
    $input = json_decode(stream_get_contents(STDIN), true, 4, JSON_THROW_ON_ERROR);
    $organizationId = is_string($input['organizationId'] ?? null) ? $input['organizationId'] : '';
    if (preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $organizationId) !== 1) {
        rejectSeedInput('input', __LINE__);
    }

    $stage = 'connect';
    $database = new PDO(
        $dsn,
        $databaseUser,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $database->beginTransaction();
    $insert = $database->prepare(<<<'SQL'
        INSERT INTO service_tokens
            (token_id, name, organization_id, token_hash, scopes, created_at, expires_at)
        VALUES (:id, :name, :organization, :hash, '["forms:read"]'::jsonb, :created, :expires)
        SQL);
    $base = new DateTimeImmutable('-4 hours');
    $expires = new DateTimeImmutable('+1 day');
    $oldestId = '';
    for ($index = 0; $index < 105; ++$index) {
        $digest = hash('sha256', $organizationId . ':pagination:' . $index, true);
        $id = rtrim(strtr(base64_encode(substr($digest, 0, 12)), '+/', '-_'), '=');
        if ($index === 0) { $oldestId = $id; }
        $insert->execute([
            'id' => $id,
            'name' => sprintf('pagination-fixture-%03d', $index),
            'organization' => $organizationId,
            'hash' => hash('sha256', 'hash:' . $organizationId . ':' . $index),
            'created' => $base->modify('+' . $index . ' seconds')->format(DATE_ATOM),
            'expires' => $expires->format(DATE_ATOM),
        ]);
    }
    $database->commit();
    fwrite(STDOUT, json_encode(['count' => 105, 'oldestId' => $oldestId, 'oldestName' => 'pagination-fixture-000'], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    if (isset($database) && $database instanceof PDO && $database->inTransaction()) { $database->rollBack(); }
    fwrite(STDERR, json_encode([
        'stage' => $stage,
        'exception' => get_class($error),
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
