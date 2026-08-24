<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('applies the four one-payout migrations in order and exposes the final schema', function (): void {
    $migrationNames = [
        '2026_08_24_100000_remove_payout_attempt_id_from_provider_webhook_receipts_table',
        '2026_08_24_100001_drop_payout_attempts_table',
        '2026_08_24_100002_create_payouts_table',
        '2026_08_24_100003_add_payout_id_to_provider_webhook_receipts_table',
    ];

    expect(DB::table('migrations')
        ->whereIn('migration', $migrationNames)
        ->orderBy('id')
        ->pluck('migration')
        ->all())->toBe($migrationNames)
        ->and(Schema::hasTable('payout_attempts'))->toBeFalse()
        ->and(Schema::hasTable('payouts'))->toBeTrue()
        ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_attempt_id'))->toBeFalse()
        ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_id'))->toBeTrue()
        ->and(Schema::getColumnListing('payouts'))->toBe([
            'id',
            'cashback_reward_id',
            'payout_account_id',
            'provider',
            'provider_reference',
            'provider_recipient_code',
            'amount_minor',
            'currency',
            'status',
            'provider_transfer_code',
            'provider_http_status',
            'provider_error_code',
            'provider_error_message',
            'provider_latency_ms',
            'observed_balance_minor',
            'succeeded_at',
            'reversed_at',
            'started_at',
            'completed_at',
            'created_at',
            'updated_at',
            'support_alert_requested_at',
        ])->not->toContain('attempt_number')
        ->and(Schema::hasIndex(
            'payouts',
            'payouts_cashback_reward_id_unique',
            'unique',
        ))->toBeTrue();
});

it('rolls back and reapplies the exact four migrations through their intentional bridge schemas', function (): void {
    $migrationFiles = [
        '2026_08_24_100000_remove_payout_attempt_id_from_provider_webhook_receipts_table.php',
        '2026_08_24_100001_drop_payout_attempts_table.php',
        '2026_08_24_100002_create_payouts_table.php',
        '2026_08_24_100003_add_payout_id_to_provider_webhook_receipts_table.php',
    ];
    $migrations = array_map(
        static function (string $file): Migration {
            /** @var Migration $migration */
            $migration = require database_path("migrations/{$file}");

            return $migration;
        },
        $migrationFiles,
    );
    $rolledBackIndexes = [];

    expect(DB::table('payouts')->count())->toBe(0)
        ->and(DB::table('provider_webhook_receipts')->count())->toBe(0);

    try {
        $migrations[3]->down();
        $rolledBackIndexes[] = 3;

        expect(Schema::hasTable('payouts'))->toBeTrue()
            ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_id'))->toBeFalse()
            ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_attempt_id'))->toBeFalse();

        $migrations[2]->down();
        $rolledBackIndexes[] = 2;

        expect(Schema::hasTable('payouts'))->toBeFalse()
            ->and(Schema::hasTable('payout_attempts'))->toBeFalse();

        $migrations[1]->down();
        $rolledBackIndexes[] = 1;

        expect(Schema::hasTable('payout_attempts'))->toBeTrue()
            ->and(Schema::hasColumn('payout_attempts', 'attempt_number'))->toBeTrue()
            ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_attempt_id'))->toBeFalse();

        $migrations[0]->down();
        $rolledBackIndexes[] = 0;

        expect(Schema::hasColumn('provider_webhook_receipts', 'payout_attempt_id'))->toBeTrue()
            ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_id'))->toBeFalse();
    } finally {
        foreach (array_reverse($rolledBackIndexes) as $index) {
            $migrations[$index]->up();
        }
    }

    expect(Schema::hasTable('payout_attempts'))->toBeFalse()
        ->and(Schema::hasTable('payouts'))->toBeTrue()
        ->and(Schema::hasColumn('payouts', 'attempt_number'))->toBeFalse()
        ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_attempt_id'))->toBeFalse()
        ->and(Schema::hasColumn('provider_webhook_receipts', 'payout_id'))->toBeTrue();
});
