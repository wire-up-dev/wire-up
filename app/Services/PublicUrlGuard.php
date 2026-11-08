<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final class PublicUrlGuard
{
    private const int MAX_REDIRECTS = 3;

    /**
     * @var list<string>
     */
    private const array EXTRA_BLOCKED_RANGES = ['100.64.0.0/10', '198.18.0.0/15', '64:ff9b::/96', '64:ff9b:1::/48'];

    /**
     * @var (Closure(string): list<string>)|null
     */
    private static ?Closure $resolver = null;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveHostsUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public function assertPublic(string $url): void
    {
        $this->publicAddresses($url);
    }

    /**
     * @param  array<int, mixed>  $curl
     * @return array<string, mixed>
     */
    public function pinnedOptions(string $url, array $curl = []): array
    {
        $addresses = $this->publicAddresses($url);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (filter_var(mb_trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $port = parse_url($url, PHP_URL_PORT) ?? (mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443);
            $pinned = implode(',', array_map(fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip, $addresses));
            $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinned}"];
        }

        return extension_loaded('curl') ? ['allow_redirects' => false, 'curl' => $curl] : ['allow_redirects' => false];
    }

    public function get(string $url, int $timeout, int $maxBytes): Response
    {
        for ($redirects = 0; ; $redirects++) {
            $exceeded = false;

            try {
                $response = Http::withOptions([...$this->pinnedOptions($url), 'progress' => function (int $expected, int $received) use ($maxBytes, &$exceeded): bool {
                    return $exceeded = max($expected, $received) > $maxBytes;
                }])->timeout($timeout)->get($url);
            } catch (ConnectionException $exception) {
                throw $exceeded ? $this->tooLarge($maxBytes) : $exception;
            }

            if (! $response->redirect()) {
                throw_if(mb_strlen($response->body(), '8bit') > $maxBytes, $this->tooLarge($maxBytes));

                return $response;
            }

            throw_if($redirects === self::MAX_REDIRECTS, InvalidArgumentException::class, 'The URL redirected too many times.');

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
        }
    }

    /**
     * @return list<string>
     */
    private function publicAddresses(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        throw_if(! is_string($host) || $host === '' || ! in_array($scheme, ['http', 'https'], true), InvalidArgumentException::class, 'The URL is not valid.');

        $addresses = $this->resolveHost(mb_trim($host, '[]'));

        throw_if($addresses === [], InvalidArgumentException::class, 'The address of this URL could not be found.');

        foreach ($addresses as $ip) {
            throw_if(! $this->isPublic($ip), InvalidArgumentException::class, 'For security, only public internet addresses can be fetched.');
        }

        return $addresses;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($ip, self::EXTRA_BLOCKED_RANGES);
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver instanceof Closure) {
            return (self::$resolver)($host);
        }

        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_filter([...$ipv4, ...$ipv6], is_string(...)));
    }

    private function tooLarge(int $maxBytes): InvalidArgumentException
    {
        return new InvalidArgumentException('The file is larger than the '.intdiv($maxBytes, 1024 * 1024).' MB limit.');
    }
}
