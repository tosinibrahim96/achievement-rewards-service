<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public const string ATTRIBUTE = 'request_id';

    public const string HEADER = 'X-Request-ID';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Context::add(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
