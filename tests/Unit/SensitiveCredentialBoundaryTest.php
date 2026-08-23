<?php

declare(strict_types=1);

use App\Actions\Auth\LoginCustomer;
use App\Actions\Auth\RegisterCustomer;
use App\Actions\Cashback\HandlePaystackWebhook;
use App\Actions\Cashback\ProcessCashbackPayment;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Auth\LoginCustomerInput;
use App\Data\Auth\RegisterCustomerInput;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Infrastructure\Payments\PaystackCashbackTransferGateway;
use App\Infrastructure\Payments\PaystackClient;
use App\Infrastructure\Payments\PaystackResponse;
use App\Infrastructure\Payments\PaystackTransferRecipientGateway;

it('marks credential-bearing Action inputs as sensitive', function (string $action): void {
    $parameter = (new ReflectionMethod($action, 'handle'))->getParameters()[0];

    expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    LoginCustomer::class,
    RegisterCustomer::class,
]);

it('marks password constructor parameters as sensitive', function (string $input): void {
    $constructor = (new ReflectionClass($input))->getConstructor();

    expect($constructor)->not->toBeNull();

    $password = collect($constructor?->getParameters() ?? [])
        ->firstWhere(fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'password');

    expect($password)->toBeInstanceOf(ReflectionParameter::class)
        ->and($password->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    LoginCustomerInput::class,
    RegisterCustomerInput::class,
]);

it('marks the complete payout account input at the fallible Action boundary', function (): void {
    $parameter = (new ReflectionMethod(RegisterPayoutAccount::class, 'handle'))->getParameters()[1];

    expect($parameter->getType()?->getName())->toBe(RegisterPayoutAccountInput::class)
        ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

it('marks the full account number as sensitive while constructing payout input', function (): void {
    $constructor = (new ReflectionClass(RegisterPayoutAccountInput::class))->getConstructor();
    $accountNumber = collect($constructor?->getParameters() ?? [])
        ->firstWhere(fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'accountNumber');

    expect($accountNumber)->toBeInstanceOf(ReflectionParameter::class)
        ->and($accountNumber->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

it('marks the configured Paystack secret at the infrastructure constructor boundary', function (): void {
    $constructor = (new ReflectionClass(PaystackClient::class))->getConstructor();
    $secretKey = collect($constructor?->getParameters() ?? [])
        ->firstWhere(fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'secretKey');

    expect($secretKey)->toBeInstanceOf(ReflectionParameter::class)
        ->and($secretKey->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

it('marks Paystack account data and provider envelopes at every fallible infrastructure boundary', function (): void {
    $boundaries = [
        [PaystackTransferRecipientGateway::class, 'createRecipient', 'input'],
        [PaystackTransferRecipientGateway::class, 'successfulData', 'response'],
        [PaystackTransferRecipientGateway::class, 'throwFailure', 'response'],
        [PaystackTransferRecipientGateway::class, 'requiredString', 'values'],
        [PaystackClient::class, 'get', 'query'],
        [PaystackClient::class, 'post', 'payload'],
        [PaystackClient::class, 'send', 'options'],
        [PaystackClient::class, 'decode', 'response'],
        [PaystackResponse::class, '__construct', 'payload'],
        [PaystackCashbackTransferGateway::class, 'mapCreatedTransfer', 'response'],
        [PaystackCashbackTransferGateway::class, 'hasValidTransferFacts', 'data'],
        [PaystackCashbackTransferGateway::class, 'mapRejectedTransfer', 'response'],
        [PaystackCashbackTransferGateway::class, 'ambiguousResponse', 'response'],
        [PaystackCashbackTransferGateway::class, 'isTransferNotFound', 'response'],
        [PaystackCashbackTransferGateway::class, 'transferCodeFrom', 'response'],
    ];

    foreach ($boundaries as [$class, $method, $parameterName]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())
            ->firstWhere(fn (ReflectionParameter $candidate): bool => $candidate->getName() === $parameterName);

        expect($parameter)->toBeInstanceOf(ReflectionParameter::class)
            ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});

it('marks raw and provider-identity callback values at fallible boundaries', function (): void {
    $boundaries = [
        [HandlePaystackWebhook::class, 'handle', 'rawBody'],
        [HandlePaystackWebhook::class, 'handle', 'signature'],
        [HandlePaystackWebhook::class, 'record', 'rawBody'],
        [HandlePaystackWebhook::class, 'callbackFrom', 'data'],
        [HandlePaystackWebhook::class, 'callbackFrom', 'providerReference'],
        [HandlePaystackWebhook::class, 'applyCallback', 'receipt'],
        [HandlePaystackWebhook::class, 'applyCallback', 'callback'],
        [HandlePaystackWebhook::class, 'matchesStoredPayment', 'reward'],
        [HandlePaystackWebhook::class, 'matchesStoredPayment', 'attempt'],
        [HandlePaystackWebhook::class, 'matchesStoredPayment', 'callback'],
        [HandlePaystackWebhook::class, 'rewardStatusMatchesAttemptStatus', 'reward'],
        [HandlePaystackWebhook::class, 'rewardStatusMatchesAttemptStatus', 'attempt'],
        [HandlePaystackWebhook::class, 'finishReceipt', 'receipt'],
        [HandlePaystackWebhook::class, 'decodeObject', 'rawBody'],
        [HandlePaystackWebhook::class, 'property', 'object'],
        [HandlePaystackWebhook::class, 'safeText', 'value'],
        [ProcessCashbackPayment::class, 'finish', 'claim'],
        [ProcessCashbackPayment::class, 'finish', 'result'],
        [ProcessCashbackPayment::class, 'complete', 'claim'],
        [ProcessCashbackPayment::class, 'complete', 'result'],
        [RequestCashbackPayoutSupport::class, 'markWhileLocked', 'reward'],
        [RequestCashbackPayoutSupport::class, 'markWhileLocked', 'attempt'],
        [PaystackClient::class, 'matchesWebhookSignature', 'rawBody'],
        [PaystackClient::class, 'matchesWebhookSignature', 'signature'],
    ];

    foreach ($boundaries as [$class, $method, $parameterName]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())
            ->firstWhere(fn (ReflectionParameter $candidate): bool => $candidate->getName() === $parameterName);

        expect($parameter)->toBeInstanceOf(ReflectionParameter::class)
            ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});
