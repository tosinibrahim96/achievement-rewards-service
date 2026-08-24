<?php

declare(strict_types=1);

use App\Enums\PaymentProvider;
use App\Enums\ProviderWebhookReceiptResult;
use App\Models\Payout;
use App\Models\ProviderWebhookReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('keeps only the approved privacy-minimized receipt columns', function (): void {
    expect(Schema::getColumnListing('provider_webhook_receipts'))->toBe([
        'id',
        'provider',
        'body_hash',
        'event_type',
        'provider_reference',
        'result',
        'received_at',
        'payout_id',
    ])->not->toContain(
        'raw_body',
        'signature',
        'provider_transfer_code',
        'provider_recipient_code',
        'amount_minor',
        'currency',
        'reason',
        'request_id',
        'correlation_id',
        'created_at',
        'updated_at',
    );

    expect(Schema::hasIndex(
        'provider_webhook_receipts',
        'provider_webhook_receipts_provider_body_hash_unique',
        'unique',
    ))->toBeTrue();
});

it('casts provenance result receipt time and the optional payout relationship', function (): void {
    $receipt = ProviderWebhookReceipt::factory()->create();

    expect($receipt->provider)->toBe(PaymentProvider::Paystack)
        ->and($receipt->result)->toBe(ProviderWebhookReceiptResult::Unsupported)
        ->and($receipt->received_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($receipt->payout)->toBeNull()
        ->and($receipt->toArray())->not->toHaveKeys(['body_hash', 'provider_reference']);
});

it('rejects invalid receipt provenance hash and result values in postgres', function (array $invalid): void {
    $receipt = ProviderWebhookReceipt::factory()->create();

    expect(fn () => DB::table('provider_webhook_receipts')
        ->where('id', $receipt->id)
        ->update($invalid))->toThrow(QueryException::class);
})->with([
    'other provider' => [['provider' => 'fake']],
    'uppercase hash' => [['body_hash' => str_repeat('A', 64)]],
    'non hexadecimal hash' => [['body_hash' => str_repeat('g', 64)]],
    'short hash' => [['body_hash' => str_repeat('a', 63)]],
    'long hash' => [['body_hash' => str_repeat('a', 65)]],
    'unknown result' => [['result' => 'accepted']],
]);

it('enforces provider-scoped exact-body uniqueness', function (): void {
    $receipt = ProviderWebhookReceipt::factory()->create();

    expect(fn () => ProviderWebhookReceipt::factory()->create([
        'body_hash' => $receipt->body_hash,
    ]))->toThrow(QueryException::class)
        ->and(ProviderWebhookReceipt::query()->count())->toBe(1);
});

it('restricts deletion of a linked payout while allowing an unlinked receipt', function (): void {
    $payout = Payout::factory()->create();
    $linked = ProviderWebhookReceipt::factory()->create([
        'payout_id' => $payout->id,
    ]);
    $unlinked = ProviderWebhookReceipt::factory()->create();

    expect($linked->payout?->is($payout))->toBeTrue()
        ->and($unlinked->payout)->toBeNull()
        ->and(fn () => $payout->delete())->toThrow(QueryException::class)
        ->and(ProviderWebhookReceipt::query()->whereKey($linked->id)->exists())->toBeTrue()
        ->and(Payout::query()->whereKey($payout->id)->exists())->toBeTrue();
});
