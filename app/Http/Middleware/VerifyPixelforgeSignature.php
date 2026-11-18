<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyPixelforgeSignature
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $header = (string) $request->header('Pixelforge-Signature');
        abort_if($secret === '' || strlen($request->getContent()) > 131072, 400, 'Invalid Pixelforge signature.');

        if (preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $header, $matches) !== 1) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $timestamp = (int) $matches[1];
        $expected = hash_hmac('sha256', $matches[1].'.'.$request->getContent(), $secret);
        abort_unless(abs(now()->getTimestamp() - $timestamp) <= 300 && hash_equals($expected, $matches[2]), 400, 'Invalid Pixelforge signature.');

        return $next($request);
    }
}
