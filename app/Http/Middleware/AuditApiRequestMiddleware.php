<?php

namespace App\Http\Middleware;

use App\Audit\AuditRecorderMiddleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditApiRequestMiddleware
{
    public function __construct(
        private readonly AuditRecorderMiddleware $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->recorder->handle($request, $next);
    }
}
