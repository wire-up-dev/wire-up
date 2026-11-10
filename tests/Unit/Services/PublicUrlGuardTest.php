<?php

declare(strict_types=1);

use App\Services\PublicUrlGuard;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('pins the request to the addresses it checked', function (string $url, array $addresses, string $pin): void {
    PublicUrlGuard::resolveHostsUsing(fn (): array => $addresses);

    expect(new PublicUrlGuard()->pinnedOptions($url))
        ->toBe(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$pin]]]);
})->with([
    'https default port' => ['https://acme.example/a', ['93.184.215.14'], 'acme.example:443:93.184.215.14'],
    'http default port' => ['http://acme.example/a', ['93.184.215.14'], 'acme.example:80:93.184.215.14'],
    'explicit port and ipv6' => ['https://acme.example:8443/a', ['93.184.215.14', '2606:4700::1'], 'acme.example:8443:93.184.215.14,[2606:4700::1]'],
]);

it('needs no pin for a public ip address', function (): void {
    expect(new PublicUrlGuard()->pinnedOptions('https://93.184.215.14/a'))->toBe(['allow_redirects' => false, 'curl' => []]);
});

it('refuses a host that resolves to a private or internal address', function (string $address): void {
    PublicUrlGuard::resolveHostsUsing(fn (): array => ['93.184.215.14', $address]);

    new PublicUrlGuard()->assertPublic('https://rebind.example/');
})->throws(InvalidArgumentException::class, 'only public internet addresses')->with([
    'private' => '10.0.0.5',
    'link local' => '169.254.169.254',
    'carrier-grade nat' => '100.100.100.200',
    'benchmark range' => '198.18.0.1',
    'nat64 loopback' => '64:ff9b::7f00:1',
    'ipv4-mapped loopback' => '::ffff:127.0.0.1',
]);

it('refuses a host that does not resolve', function (): void {
    PublicUrlGuard::resolveHostsUsing(fn (): array => []);

    new PublicUrlGuard()->assertPublic('https://nowhere.example/');
})->throws(InvalidArgumentException::class, 'could not be found');

it('refuses urls that are not http or https', function (string $url): void {
    new PublicUrlGuard()->assertPublic($url);
})->throws(InvalidArgumentException::class, 'not valid')->with(['file:///etc/passwd', 'ftp://acme.example/x', 'not a url']);

it('resolves real hostnames when no resolver is set', function (): void {
    PublicUrlGuard::resolveHostsUsing(null);

    new PublicUrlGuard()->assertPublic('http://localhost/');
})->throws(InvalidArgumentException::class, 'only public internet addresses');

it('follows redirects itself and checks every hop', function (): void {
    Http::fake([
        'acme.example/start' => Http::response('', 302, ['Location' => '/next']),
        'acme.example/next' => Http::response('', 301, ['Location' => 'https://cdn.acme.example/file']),
        'cdn.acme.example/file' => Http::response('done'),
    ]);

    expect(new PublicUrlGuard()->get('https://acme.example/start', 5, 1024)->body())->toBe('done');

    Http::assertSentCount(3);
});

it('stops a redirect to an internal address before calling it', function (): void {
    Http::fake(['acme.example/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

    try {
        new PublicUrlGuard()->get('https://acme.example/go', 5, 1024);
    } finally {
        Http::assertSentCount(1);
    }
})->throws(InvalidArgumentException::class, 'only public internet addresses');

it('gives up after too many redirects', function (): void {
    Http::fake(['acme.example/*' => fn (): PromiseInterface => Http::response('', 302, ['Location' => '/again'])]);

    new PublicUrlGuard()->get('https://acme.example/loop', 5, 1024);
})->throws(InvalidArgumentException::class, 'too many times');

it('stops reading a response that grows past the limit', function (): void {
    Http::fake(['acme.example/*' => Http::response(str_repeat('a', 2 * 1024 * 1024 + 1))]);

    new PublicUrlGuard()->get('https://acme.example/big', 5, 2 * 1024 * 1024);
})->throws(InvalidArgumentException::class, 'larger than the 2 MB limit');

it('aborts a download once it grows past the limit', function (): void {
    Http::fake(['acme.example/*' => function (Request $request, array $options): never {
        expect($options['progress'](0, 1024, 0, 0))->toBeFalse();

        $options['progress'](0, 2 * 1024 * 1024 + 1, 0, 0);

        throw new ConnectionException('An error was encountered during the progress event');
    }]);

    new PublicUrlGuard()->get('https://acme.example/big', 5, 2 * 1024 * 1024);
})->throws(InvalidArgumentException::class, 'larger than the 2 MB limit');

it('asks for the uncompressed file so a compressed bomb cannot slip past the limit', function (): void {
    Http::fake(['acme.example/*' => function (Request $request, array $options): PromiseInterface {
        expect($request->header('Accept-Encoding'))->toBe(['identity'])
            ->and($options['decode_content'])->toBeFalse();

        return Http::response('ok');
    }]);

    expect(new PublicUrlGuard()->get('https://acme.example/file', 5, 1024)->body())->toBe('ok');
});

it('passes other connection failures through', function (): void {
    Http::fake(['acme.example/*' => fn (): never => throw new ConnectionException('cURL error 6: Could not resolve host')]);

    new PublicUrlGuard()->get('https://acme.example/down', 5, 1024);
})->throws(ConnectionException::class, 'Could not resolve host');
