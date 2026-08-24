<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\AchievementProgressController;
use App\Models\Achievement;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(DatabaseMigrations::class);

/**
 * @param  list<string>  $abilities
 * @return array<string, string>
 */
function achievementAuthHeaders(User $user, array $abilities): array
{
    return [
        'Authorization' => 'Bearer '.$user->createToken('achievement-progress-test', $abilities)->plainTextToken,
    ];
}

function grantApiAchievement(User $user, string $code): void
{
    $achievement = Achievement::query()->where('code', $code)->firstOrFail();

    UserAchievement::factory()
        ->for($user)
        ->for($achievement)
        ->create();
}

function grantApiBadge(User $user, string $code): void
{
    $badge = Badge::query()->where('code', $code)->firstOrFail();

    UserBadge::factory()
        ->for($user)
        ->for($badge)
        ->create();
}

it('registers the protected achievements route', function (): void {
    $route = Route::getRoutes()->getByName('users.achievements.show');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('users/{user}/achievements')
        ->and($route?->methods())->toBe(['GET', 'HEAD'])
        ->and($route?->getActionName())->toBe(AchievementProgressController::class.'@show')
        ->and($route?->gatherMiddleware())->toContain(
            'web',
            'auth:sanctum',
            'abilities:'.TokenAbility::AchievementsRead->value,
            'customer-account',
            'can:viewAchievements,user',
        );
});

it('allows customers to view only their own progress', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $system = User::factory()->system()->create();

    expect(Gate::forUser($customer)->allows('viewAchievements', $customer))->toBeTrue()
        ->and(Gate::forUser($customer)->denies('viewAchievements', $otherCustomer))->toBeTrue()
        ->and(Gate::forUser($system)->denies('viewAchievements', $system))->toBeTrue();
});

it('returns JSON 401 before user lookup when the Accept header is missing', function (): void {
    $target = User::factory()->create();

    foreach (["/users/{$target->id}/achievements", '/users/999999/achievements'] as $path) {
        $this->get($path)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson([
                'code' => 'unauthenticated',
                'message' => 'A valid bearer token is required.',
            ]);
    }
});

it('requires achievements read access', function (): void {
    $customer = User::factory()->create();

    $this->get(
        "/users/{$customer->id}/achievements",
        achievementAuthHeaders($customer, []),
    )
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'forbidden');
});

it('accepts an existing first-party customer session on the web-group route', function (): void {
    $customer = User::factory()->create();
    $this->seed([
        AchievementCatalogueSeeder::class,
        BadgeCatalogueSeeder::class,
    ]);

    $this->actingAs($customer, 'web')
        ->getJson("/users/{$customer->id}/achievements")
        ->assertOk()
        ->assertJsonPath('unlocked_achievements', []);
});

it('requires a customer account', function (): void {
    $system = User::factory()->system()->create();

    $this->get(
        "/users/{$system->id}/achievements",
        achievementAuthHeaders($system, [TokenAbility::AchievementsRead->value]),
    )
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'forbidden');
});

it("prevents a customer from viewing another customer's progress", function (): void {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    $this->get(
        "/users/{$target->id}/achievements",
        achievementAuthHeaders($actor, [TokenAbility::AchievementsRead->value]),
    )
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'code' => 'forbidden',
            'message' => 'You are not allowed to perform this action.',
        ]);
});

it('returns exactly the five progress fields for the customer', function (): void {
    $customer = User::factory()->create();
    $this->seed([
        AchievementCatalogueSeeder::class,
        BadgeCatalogueSeeder::class,
    ]);
    grantApiAchievement($customer, 'first-purchase');
    grantApiAchievement($customer, 'five-thousand-spent');
    grantApiBadge($customer, 'beginner');

    $response = $this->getJson(
        "/users/{$customer->id}/achievements",
        achievementAuthHeaders($customer, [TokenAbility::AchievementsRead->value]),
    )->assertOk();

    $response->assertExactJson([
        'unlocked_achievements' => [
            'First Purchase',
            'NGN 5,000 Spent',
        ],
        'next_available_achievements' => [
            '3 Purchases',
            'NGN 10,000 Spent',
        ],
        'current_badge' => 'Beginner',
        'next_badge' => 'Intermediate',
        'remaining_to_unlock_next_badge' => 2,
    ]);
});

it('returns a JSON 404 for a missing user', function (): void {
    $customer = User::factory()->create();

    $this->get(
        '/users/999999/achievements',
        achievementAuthHeaders($customer, [TokenAbility::AchievementsRead->value]),
    )
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'code' => 'not_found',
            'message' => 'The requested resource was not found.',
        ]);
});

it('returns 404 for a non-numeric user ID', function (): void {
    $customer = User::factory()->create();

    $this->getJson(
        '/users/not-a-number/achievements',
        achievementAuthHeaders($customer, [TokenAbility::AchievementsRead->value]),
    )
        ->assertNotFound()
        ->assertExactJson([
            'code' => 'not_found',
            'message' => 'The requested resource was not found.',
        ]);
});
