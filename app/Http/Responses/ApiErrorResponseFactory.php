<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\PaymentProviderFailure;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Payments\PaymentProviderException;
use App\Exceptions\Payouts\PayoutAccountBusyException;
use App\Exceptions\Payouts\PayoutAccountConflictException;
use App\Exceptions\Purchases\PurchaseReferenceConflictException;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class ApiErrorResponseFactory
{
    public function fromException(
        Request $request,
        Throwable $exception,
        ?Response $renderedResponse = null,
    ): JsonResponse {
        [$status, $message, $code] = $this->describe($exception, $renderedResponse);
        $requestId = $this->requestId($request);
        $headers = $this->headers($exception, $renderedResponse);

        if ($exception instanceof AuthenticationException && ! array_key_exists('WWW-Authenticate', $headers)) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        $headers['Content-Type'] = 'application/json';
        $headers[AssignRequestId::HEADER] = $requestId;

        $payload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @return array{int, string, string}
     */
    private function describe(Throwable $exception, ?Response $renderedResponse): array
    {
        if ($exception instanceof InvalidCredentialsException) {
            return [
                Response::HTTP_UNAUTHORIZED,
                'The provided credentials are incorrect.',
                'invalid_credentials',
            ];
        }

        if ($exception instanceof ValidationException) {
            return [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                'validation_failed',
            ];
        }

        if ($exception instanceof PurchaseReferenceConflictException) {
            return [
                Response::HTTP_CONFLICT,
                'The external reference is already associated with a different purchase.',
                'purchase_reference_conflict',
            ];
        }

        if ($exception instanceof PayoutAccountBusyException) {
            return [
                Response::HTTP_CONFLICT,
                'Another payout account update is already in progress.',
                'payout_account_busy',
            ];
        }

        if ($exception instanceof PayoutAccountConflictException) {
            return [
                Response::HTTP_CONFLICT,
                'The payout account conflicts with an existing destination.',
                'payout_account_conflict',
            ];
        }

        if ($exception instanceof PaymentProviderException) {
            return match ($exception->failure) {
                PaymentProviderFailure::RecipientRejected => [
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'The payout account could not be verified.',
                    'payout_account_rejected',
                ],
                PaymentProviderFailure::Unavailable => [
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'The payout account provider is temporarily unavailable.',
                    'payment_provider_unavailable',
                ],
                PaymentProviderFailure::MalformedResponse => [
                    Response::HTTP_BAD_GATEWAY,
                    'The payout account provider returned an invalid response.',
                    'payment_provider_invalid_response',
                ],
                PaymentProviderFailure::Timeout => [
                    Response::HTTP_GATEWAY_TIMEOUT,
                    'The payout account provider did not respond in time.',
                    'payment_provider_timeout',
                ],
            };
        }

        if ($exception instanceof AuthenticationException) {
            return $this->describeStatus(Response::HTTP_UNAUTHORIZED);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->describeStatus($exception->getStatusCode());
        }

        if ($renderedResponse !== null && $renderedResponse->getStatusCode() >= 400) {
            return $this->describeStatus($renderedResponse->getStatusCode());
        }

        return $this->describeStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @return array{int, string, string}
     */
    private function describeStatus(int $status): array
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => [$status, 'The request could not be processed.', 'bad_request'],
            Response::HTTP_UNAUTHORIZED => [$status, 'A valid bearer token is required.', 'unauthenticated'],
            Response::HTTP_FORBIDDEN => [$status, 'You are not allowed to perform this action.', 'forbidden'],
            Response::HTTP_NOT_FOUND => [$status, 'The requested resource was not found.', 'not_found'],
            Response::HTTP_METHOD_NOT_ALLOWED => [$status, 'The HTTP method is not allowed for this route.', 'method_not_allowed'],
            Response::HTTP_CONFLICT => [$status, 'The request conflicts with the current resource state.', 'conflict'],
            Response::HTTP_TOO_MANY_REQUESTS => [$status, 'Too many requests were made. Try again later.', 'rate_limit_exceeded'],
            Response::HTTP_INTERNAL_SERVER_ERROR => [$status, 'An unexpected error occurred.', 'internal_server_error'],
            Response::HTTP_BAD_GATEWAY => [$status, 'An upstream service returned an invalid response.', 'upstream_service_error'],
            Response::HTTP_SERVICE_UNAVAILABLE => [$status, 'The service is temporarily unavailable.', 'service_unavailable'],
            Response::HTTP_GATEWAY_TIMEOUT => [$status, 'An upstream service did not respond in time.', 'upstream_service_timeout'],
            default => $status >= 400 && $status < 500
                ? [$status, 'The request could not be completed.', 'http_error']
                : [$status, 'An unexpected server error occurred.', 'server_error'],
        };
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function headers(Throwable $exception, ?Response $renderedResponse): array
    {
        $headers = [];

        if ($renderedResponse !== null) {
            foreach (['WWW-Authenticate', 'Allow', 'Retry-After'] as $headerName) {
                $headerValue = $renderedResponse->headers->get($headerName);

                if ($headerValue !== null) {
                    $headers[$headerName] = $headerValue;
                }
            }
        }

        if ($exception instanceof HttpExceptionInterface) {
            /** @var array<string, string|list<string>> $exceptionHeaders */
            $exceptionHeaders = $exception->getHeaders();
            $headers = array_replace($headers, $exceptionHeaders);
        }

        return $headers;
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->attributes->get(AssignRequestId::ATTRIBUTE);

        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $requestId = (string) Str::ulid();
        $request->attributes->set(AssignRequestId::ATTRIBUTE, $requestId);
        Context::add(AssignRequestId::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
