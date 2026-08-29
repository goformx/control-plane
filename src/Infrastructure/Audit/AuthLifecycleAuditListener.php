<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Auth\Event\AuthLifecycleEvent;

final readonly class AuthLifecycleAuditListener
{
    public function __construct(private AuditWriterInterface $writer) {}

    public function record(AuthLifecycleEvent $event): void
    {
        $userId = filter_var($event->aggregateId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($userId)) {
            return;
        }

        $this->writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            // Registration begins as an anonymous request. The other events
            // establish or act on the identified account itself.
            accountUid: $event->action === 'registered' ? 0 : $userId,
            subjectUri: '/accounts/' . $userId . '/authentication',
            outcome: 'allowed',
            severity: $event->action === 'login_succeeded' ? 'info' : 'notice',
            entityTypeId: 'user',
            attributes: [
                'action' => 'auth.' . $event->action,
                'disposition' => $event->disposition,
            ],
        ));
    }
}
