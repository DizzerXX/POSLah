<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issues a sync token for a registered till.
 *
 * A stopgap standing in for device registration in the backoffice, which belongs to step 4.
 * It exists so the sync engine has a real credential path rather than tokens that only
 * appear inside tests.
 */
class IssueDeviceTokenCommand extends Command
{
    protected $signature = 'poslah:device-token
                            {device : Device id, or its device_name}
                            {--pull-only : Issue a read-only token that cannot push sales}';

    protected $description = 'Issue a Sanctum sync token for a till device';

    public function handle(): int
    {
        $identifier = (string) $this->argument('device');

        // The id comparison is guarded: PostgreSQL raises rather than returning false when a
        // uuid column is compared against text that is not a uuid, so an unguarded
        // `where(id, 'Front Counter')` is an error, not a miss.
        $device = Device::query()
            ->when(
                Str::isUuid($identifier),
                fn ($query) => $query->where('id', $identifier),
                fn ($query) => $query->where('device_name', $identifier),
            )
            ->first();

        if ($device === null) {
            $this->error("No device matches '{$identifier}'.");

            return self::FAILURE;
        }

        $abilities = $this->option('pull-only')
            ? [Device::ABILITY_PULL]
            : [Device::ABILITY_PULL, Device::ABILITY_PUSH];

        $token = $device->createToken("till:{$device->device_name}", $abilities);

        $this->info("Device:    {$device->device_name} ({$device->id})");
        $this->info('Abilities: '.implode(', ', $abilities));
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->comment('Shown once. Store it on the till; it is not recoverable.');

        return self::SUCCESS;
    }
}
