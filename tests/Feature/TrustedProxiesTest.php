<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

function clientIpBehindProxy(): string
{
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

    $request = Request::create('/', server: ['REMOTE_ADDR' => '172.70.1.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);

    new TrustProxies()->handle($request, fn (): Response => new Response());

    return (string) $request->ip();
}

it('ignores forwarded addresses unless the proxy in front is trusted', function (): void {
    expect(clientIpBehindProxy())->toBe('172.70.1.1');
});

it('uses the visitor address a trusted proxy such as Cloudflare forwards', function (string $proxies): void {
    config(['trustedproxy.proxies' => $proxies]);

    expect(clientIpBehindProxy())->toBe('203.0.113.9');
})->with(['every proxy' => '*', 'a listed range' => '172.64.0.0/13, 104.16.0.0/13']);
