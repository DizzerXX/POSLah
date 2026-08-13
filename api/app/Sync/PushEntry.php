<?php

namespace App\Sync;

/**
 * One outbox entry as it arrived. A self-contained, replayable fact: the payload is the
 * snapshot the till took at enqueue time, not a re-read of a row that may have moved on
 * since.
 */
final readonly class PushEntry
{
    public function __construct(
        public string $id,
        public ?EntityType $entityType,
        public string $entityId,
        public string $operation,
        public array $payload,
        /** Position in the batch as sent, used to keep the dependency sort stable. */
        public int $position,
    ) {}

    public static function fromArray(array $raw, int $position): self
    {
        return new self(
            id: (string) $raw['id'],
            entityType: EntityType::tryFrom((string) ($raw['entity_type'] ?? '')),
            entityId: (string) ($raw['entity_id'] ?? ''),
            operation: (string) ($raw['operation'] ?? ''),
            payload: (array) ($raw['payload'] ?? []),
            position: $position,
        );
    }
}
