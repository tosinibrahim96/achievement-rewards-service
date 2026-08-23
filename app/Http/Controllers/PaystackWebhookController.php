<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cashback\HandlePaystackWebhook;
use Illuminate\Http\Request;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

final readonly class PaystackWebhookController
{
    public function __construct(private HandlePaystackWebhook $handleWebhook) {}

    public function __invoke(#[SensitiveParameter] Request $request): Response
    {
        $signature = $request->header('x-paystack-signature');

        $this->handleWebhook->handle(
            $request->getContent(),
            is_string($signature) ? $signature : null,
        );

        return new Response(status: Response::HTTP_OK);
    }
}
