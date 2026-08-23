<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\TokenAbility;
use App\Models\Badge;
use App\Models\CashbackReward;
use App\Models\User;
use App\Models\UserBadge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(DatabaseMigrations::class);

/** @return array<string, string> */
function cashbackRewardApiHeaders(User $user, array $abilities): array
{
    return [
        'Authorization' => 'Bearer '.$user->createToken('cashback-reward-test', $abilities)->plainTextToken,
        'Accept' => 'application/json',
    ];
}

/** @param array<string, mixed> $attributes */
function createCashbackRewardFor(
    User $user,
    string $badgeName,
    array $attributes = [],
): CashbackReward {
    $badge = Badge::factory()->create(['name' => $badgeName]);
    $userBadge = UserBadge::factory()
        ->for($user)
        ->for($badge)
        ->create();

    return CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->create($attributes);
}

it('requires authentication the cashback read ability and a customer identity', function (): void {
    $customer = User::factory()->create();

    $this->getJson('/api/me/cashback-rewards')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    $this->getJson(
        '/api/me/cashback-rewards',
        cashbackRewardApiHeaders($customer, []),
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    $system = User::factory()->system()->create();

    $this->getJson(
        '/api/me/cashback-rewards',
        cashbackRewardApiHeaders($system, [TokenAbility::CashbackRewardsRead->value]),
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('returns the exact safe owner-scoped reward contract in deterministic order', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $olderCreatedAt = CarbonImmutable::parse('2026-08-22T16:00:00Z');
    $tiedCreatedAt = CarbonImmutable::parse('2026-08-22T18:30:00Z');
    $firstTiedReward = createCashbackRewardFor($customer, 'Intermediate', [
        'amount_minor' => 30_000,
        'status' => CashbackRewardStatus::Pending,
        'created_at' => $tiedCreatedAt,
        'updated_at' => CarbonImmutable::parse('2026-08-22T18:31:00Z'),
        'last_error_code' => 'private-first-error',
        'last_error_message' => 'private first diagnostic',
    ]);
    $secondTiedReward = createCashbackRewardFor($customer, 'Advanced', [
        'amount_minor' => 30_000,
        'status' => CashbackRewardStatus::Paid,
        'created_at' => $tiedCreatedAt,
        'updated_at' => CarbonImmutable::parse('2026-08-22T18:32:00Z'),
        'paid_at' => CarbonImmutable::parse('2026-08-22T18:31:30Z'),
        'last_error_code' => 'private-second-error',
        'last_error_message' => 'private second diagnostic',
    ]);
    $olderReward = createCashbackRewardFor($customer, 'Beginner', [
        'amount_minor' => 30_000,
        'status' => CashbackRewardStatus::AwaitingFunds,
        'created_at' => $olderCreatedAt,
        'updated_at' => CarbonImmutable::parse('2026-08-22T16:01:00Z'),
    ]);
    $otherReward = createCashbackRewardFor($otherCustomer, 'Private Other Badge', [
        'created_at' => CarbonImmutable::parse('2026-08-23T11:00:00Z'),
        'last_error_message' => 'other customer diagnostic',
    ]);

    $response = $this->getJson(
        'https://rewards.example.test/api/me/cashback-rewards',
        cashbackRewardApiHeaders($customer, [TokenAbility::CashbackRewardsRead->value]),
    )->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'links', 'meta'])
        ->and(array_keys($response->json('data.0')))->toBe([
            'id',
            'badge_name',
            'amount_minor',
            'currency',
            'status',
            'created_at',
            'updated_at',
            'paid_at',
        ])
        ->and($response->json('data'))->toBe([
            [
                'id' => $secondTiedReward->id,
                'badge_name' => 'Advanced',
                'amount_minor' => 30_000,
                'currency' => Currency::Ngn->value,
                'status' => CashbackRewardStatus::Paid->value,
                'created_at' => $tiedCreatedAt->utc()->toISOString(),
                'updated_at' => CarbonImmutable::parse('2026-08-22T18:32:00Z')->toISOString(),
                'paid_at' => CarbonImmutable::parse('2026-08-22T18:31:30Z')->toISOString(),
            ],
            [
                'id' => $firstTiedReward->id,
                'badge_name' => 'Intermediate',
                'amount_minor' => 30_000,
                'currency' => Currency::Ngn->value,
                'status' => CashbackRewardStatus::Pending->value,
                'created_at' => $tiedCreatedAt->utc()->toISOString(),
                'updated_at' => CarbonImmutable::parse('2026-08-22T18:31:00Z')->toISOString(),
                'paid_at' => null,
            ],
            [
                'id' => $olderReward->id,
                'badge_name' => 'Beginner',
                'amount_minor' => 30_000,
                'currency' => Currency::Ngn->value,
                'status' => CashbackRewardStatus::AwaitingFunds->value,
                'created_at' => $olderCreatedAt->utc()->toISOString(),
                'updated_at' => CarbonImmutable::parse('2026-08-22T16:01:00Z')->toISOString(),
                'paid_at' => null,
            ],
        ])
        ->and($response->json('links'))->toBe([
            'first' => 'https://rewards.example.test/api/me/cashback-rewards?page=1',
            'last' => 'https://rewards.example.test/api/me/cashback-rewards?page=1',
            'prev' => null,
            'next' => null,
        ])
        ->and($response->json('meta'))->toBe([
            'current_page' => 1,
            'per_page' => 20,
            'last_page' => 1,
            'total' => 3,
        ]);

    $serialized = $response->getContent();

    expect($serialized)->not->toContain($firstTiedReward->provider_reference)
        ->and($serialized)->not->toContain($secondTiedReward->provider_reference)
        ->and($serialized)->not->toContain('private-first-error')
        ->and($serialized)->not->toContain('private first diagnostic')
        ->and($serialized)->not->toContain('private-second-error')
        ->and($serialized)->not->toContain('private second diagnostic')
        ->and($serialized)->not->toContain('Private Other Badge')
        ->and($serialized)->not->toContain('other customer diagnostic')
        ->and(collect($response->json('data'))->pluck('id')->all())->not->toContain($otherReward->id);
});

it('returns a fixed length-aware page with exact links and bounded eager-loaded queries', function (): void {
    $customer = User::factory()->create();
    $createdAt = CarbonImmutable::parse('2026-08-22T18:30:00Z');
    $rewardIds = [];

    foreach (range(1, 21) as $sequence) {
        $rewardIds[] = createCashbackRewardFor($customer, "Badge {$sequence}", [
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->id;
    }

    Sanctum::actingAs($customer, [TokenAbility::CashbackRewardsRead->value]);
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $firstPage = $this->getJson(
        'https://rewards.example.test/api/me/cashback-rewards',
    )->assertOk();

    expect($firstPage->json('data'))->toHaveCount(20)
        ->and(collect($firstPage->json('data'))->pluck('id')->all())
        ->toBe(array_slice(array_reverse($rewardIds), 0, 20))
        ->and($firstPage->json('links'))->toBe([
            'first' => 'https://rewards.example.test/api/me/cashback-rewards?page=1',
            'last' => 'https://rewards.example.test/api/me/cashback-rewards?page=2',
            'prev' => null,
            'next' => 'https://rewards.example.test/api/me/cashback-rewards?page=2',
        ])
        ->and($firstPage->json('meta'))->toBe([
            'current_page' => 1,
            'per_page' => 20,
            'last_page' => 2,
            'total' => 21,
        ])
        ->and($queries)->toHaveCount(4);

    $queries = [];

    $response = $this->getJson(
        'https://rewards.example.test/api/me/cashback-rewards?page=2',
    )->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($rewardIds[0])
        ->and($response->json('links'))->toBe([
            'first' => 'https://rewards.example.test/api/me/cashback-rewards?page=1',
            'last' => 'https://rewards.example.test/api/me/cashback-rewards?page=2',
            'prev' => 'https://rewards.example.test/api/me/cashback-rewards?page=1',
            'next' => null,
        ])
        ->and($response->json('meta'))->toBe([
            'current_page' => 2,
            'per_page' => 20,
            'last_page' => 2,
            'total' => 21,
        ])
        ->and($queries)->toHaveCount(4);
});

it('maps invalid or unsupported page input through the central validation response', function (string $query): void {
    $customer = User::factory()->create();

    $this->getJson(
        '/api/me/cashback-rewards?'.$query,
        cashbackRewardApiHeaders($customer, [TokenAbility::CashbackRewardsRead->value]),
    )
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('message', 'One or more fields are invalid.')
        ->assertJsonValidationErrors(str_starts_with($query, 'per_page') ? 'per_page' : 'page');
})->with([
    'zero' => 'page=0',
    'negative' => 'page=-1',
    'decimal' => 'page=1.5',
    'text' => 'page=second',
    'array' => 'page[]=1',
    'customer-controlled page size' => 'per_page=1',
]);
