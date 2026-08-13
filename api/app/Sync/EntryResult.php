<?php

namespace App\Sync;

/**
 * The outcome of one entry.
 *
 * Results are per entry, never batch-level. One malformed entry must not block the other
 * forty-nine valid sales behind it — a batch-level failure lets a single poison entry wedge
 * the queue forever, which is the most common way a naive sync engine dies in production.
 */
final readonly class EntryResult
{
    private function __construct(
        public string $entryId,
        public EntryStatus $status,
        public ?RejectionReason $reason = null,
        public ?string $detail = null,
    ) {}

    public static function accepted(string $entryId): self
    {
        return new self($entryId, EntryStatus::Accepted);
    }

    public static function duplicate(string $entryId): self
    {
        return new self($entryId, EntryStatus::Duplicate);
    }

    public static function rejected(string $entryId, RejectionReason $reason, ?string $detail = null): self
    {
        return new self($entryId, EntryStatus::Rejected, $reason, $detail);
    }

    public function toArray(): array
    {
        return array_filter([
            'entry_id' => $this->entryId,
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            'detail' => $this->detail,
        ], static fn ($value) => $value !== null);
    }
}
