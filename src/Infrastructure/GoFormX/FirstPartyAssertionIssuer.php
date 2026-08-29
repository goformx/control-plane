<?php

declare(strict_types=1);

namespace App\Infrastructure\GoFormX;

use App\Domain\GoFormX\IssuedAssertion;
use App\Domain\GoFormX\ManagementScope;
use Symfony\Component\Uid\Uuid;

final readonly class FirstPartyAssertionIssuer
{
    private const TYPE = 'gofx-fpa+jwt';
    private const VERSION = 1;
    private const LIFETIME_SECONDS = 60;

    public function __construct(
        private string $issuer,
        private string $audience,
        private SigningKey $key,
    ) {
        $this->assertHttpsOrigin($issuer, 'issuer');
        $this->assertHttpsOrigin($audience, 'audience');
    }

    /** @param non-empty-list<ManagementScope> $scopes */
    public function issue(
        string $subjectId,
        string $organizationId,
        array $scopes,
        ?string $requestId = null,
        ?\DateTimeImmutable $now = null,
    ): IssuedAssertion {
        if (!Uuid::isValid($subjectId) || !Uuid::isValid($organizationId)) {
            throw new \InvalidArgumentException('Subject and organization must be stable UUIDs.');
        }
        if ($scopes === []) {
            throw new \InvalidArgumentException('At least one management scope is required.');
        }
        $scopeValues = [];
        foreach ($scopes as $scope) {
            if (!$scope instanceof ManagementScope || isset($scopeValues[$scope->value])) {
                throw new \InvalidArgumentException('Management scopes must be canonical and duplicate-free.');
            }
            $scopeValues[$scope->value] = true;
        }
        $requestId ??= Uuid::v4()->toRfc4122();
        if (!Uuid::isValid($requestId)) {
            throw new \InvalidArgumentException('The correlation request id must be a UUID.');
        }
        $assertionId = Uuid::v4()->toRfc4122();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(
            (int) $now->format('H'),
            (int) $now->format('i'),
            (int) $now->format('s'),
        );
        $expiresAt = $now->modify('+' . self::LIFETIME_SECONDS . ' seconds');
        $header = ['alg' => 'EdDSA', 'typ' => self::TYPE, 'kid' => $this->key->id];
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $subjectId,
            'org' => $organizationId,
            'scp' => array_keys($scopeValues),
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'jti' => $assertionId,
            'rid' => $requestId,
            'ver' => self::VERSION,
        ];
        $message = $this->encode($header) . '.' . $this->encode($claims);
        $compact = $message . '.' . SigningKey::base64Url($this->key->sign($message));

        return new IssuedAssertion($compact, $assertionId, $requestId, $expiresAt);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return SigningKey::base64Url(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertHttpsOrigin(string $value, string $name): void
    {
        $parts = parse_url($value);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            throw new \InvalidArgumentException("The first-party {$name} must be a configured HTTPS origin.");
        }
    }
}
