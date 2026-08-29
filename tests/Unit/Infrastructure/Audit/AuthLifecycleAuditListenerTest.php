<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Audit;

use App\Infrastructure\Audit\AuthLifecycleAuditListener;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Auth\Event\AuthLifecycleEvent;

final class AuthLifecycleAuditListenerTest extends TestCase
{
    public function testSuccessfulLoginRecordsOnlySafeLifecycleMetadataForTheActor(): void
    {
        $writer = new RecordingAuditWriter();
        (new AuthLifecycleAuditListener($writer))->record(
            new AuthLifecycleEvent('42', 'login_succeeded', ['two_factor' => true]),
        );

        self::assertCount(1, $writer->events);
        $record = $writer->events[0];
        self::assertSame(42, $record->accountUid);
        self::assertSame('/accounts/42/authentication', $record->subjectUri);
        self::assertSame('auth.login_succeeded', $record->attributes['action']);
        self::assertSame(['two_factor' => true], $record->attributes['disposition']);
    }

    public function testRegistrationRetainsAnonymousActorSemantics(): void
    {
        $writer = new RecordingAuditWriter();
        (new AuthLifecycleAuditListener($writer))->record(new AuthLifecycleEvent('73', 'registered'));

        self::assertSame(0, $writer->events[0]->accountUid);
        self::assertSame('auth.registered', $writer->events[0]->attributes['action']);
    }
}

final class RecordingAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $events = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->events[] = $descriptor;
    }
}
