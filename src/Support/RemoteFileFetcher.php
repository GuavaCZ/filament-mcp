<?php

namespace Guava\FilamentMcp\Support;

use Guava\FilamentMcp\Exceptions\RemoteFetchException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads a file an agent pointed at, assuming the agent may have been talked
 * into pointing somewhere hostile.
 *
 * The threat is server-side request forgery: a token holder, or a prompt-injected
 * agent acting for one, gets the application server to request hosts only it can
 * reach (cloud metadata endpoints, internal admin panels, databases on localhost)
 * and smuggles the response out through a record field.
 *
 * Every guard is on by default and individually relaxable in config for apps that
 * need to fetch from their own network.
 */
class RemoteFileFetcher
{
    public function fetch(string $url): FetchedFile
    {
        if (! config('filament-mcp.remote_files.enabled', true)) {
            throw new RemoteFetchException('Fetching files from a URL is disabled on this server.');
        }

        $maxBytes = $this->getMaxBytes();
        $maxRedirects = (int) config('filament-mcp.remote_files.max_redirects', 3);

        $hops = 0;

        // Redirects are followed by hand rather than by Guzzle. Each hop has to be
        // vetted and pinned to the address that was vetted, and the pin is a
        // per-request curl option that cannot be applied to a redirect Guzzle
        // follows for us.
        while (true) {
            $response = $this->request($url, $this->assertUrlIsAllowed($url));

            $location = $response->redirect() ? $response->header('Location') : '';

            if ($location === '') {
                break;
            }

            if (++$hops > $maxRedirects) {
                throw new RemoteFetchException('The URL redirected too many times.');
            }

            $url = (string) UriResolver::resolve(Utils::uriFor($url), Utils::uriFor($location));
        }

        if (! $response->successful()) {
            throw new RemoteFetchException("The URL responded with status {$response->status()}.");
        }

        $contents = $this->readCapped($response, $maxBytes);

        if ($contents === '') {
            throw new RemoteFetchException('The URL returned an empty response.');
        }

        return new FetchedFile(
            contents: $contents,
            // Sniffed from the bytes, never from the Content-Type header, which
            // the remote server controls. The result may land on a disk that is
            // publicly served.
            mimeType: MimeSniffer::sniff($contents) ?? 'application/octet-stream',
            originalName: $this->guessName($url),
        );
    }

    /**
     * Issue one hop, connecting to the address that was just vetted.
     *
     * @throws RemoteFetchException
     */
    protected function request(string $url, ?string $pinnedIp): Response
    {
        try {
            return Http::withOptions([
                'allow_redirects' => false,
                // Streamed so the body can be cut off at the size limit. Buffering
                // it first would let one hostile URL exhaust the memory limit.
                'stream' => true,
                ...($pinnedIp === null ? [] : [
                    'curl' => [CURLOPT_RESOLVE => [$this->getResolveEntry($url, $pinnedIp)]],
                ]),
            ])
                ->timeout((int) config('filament-mcp.remote_files.timeout', 10))
                ->withHeaders(['Accept' => '*/*'])
                ->get($url)
            ;
        } catch (RemoteFetchException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RemoteFetchException('The URL could not be reached.');
        }
    }

    /**
     * @throws RemoteFetchException
     */
    protected function readCapped(Response $response, int $maxBytes): string
    {
        $body = $response->toPsrResponse()->getBody();
        $contents = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;

            if (strlen($contents) > $maxBytes) {
                throw new RemoteFetchException(
                    'The file is larger than the ' . $this->formatBytes($maxBytes) . ' limit.',
                );
            }
        }

        return $contents;
    }

    /**
     * Tell curl which address to use for this host, so the connection goes to the
     * IP that was checked rather than whatever a second DNS lookup returns. A
     * low-TTL name can otherwise answer with a public address for the check and a
     * private one for the fetch.
     */
    protected function getResolveEntry(string $url, string $ip): string
    {
        $parts = parse_url($url);

        $host = trim($parts['host'] ?? '', '[]');
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);

        // curl expects IPv6 literals bracketed in a resolve entry.
        $target = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:{$port}:{$target}";
    }

    /**
     * Returns the address the request must be pinned to, or null when this
     * server has been configured to trust the host without resolving it.
     *
     * @throws RemoteFetchException
     */
    public function assertUrlIsAllowed(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RemoteFetchException('The URL is not valid.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RemoteFetchException('Only http and https URLs can be fetched.');
        }

        $host = $parts['host'];

        $allowedHosts = (array) config('filament-mcp.remote_files.allowed_hosts', []);

        if ($allowedHosts !== []) {
            if (! $this->matchesAnyHost($host, $allowedHosts)) {
                throw new RemoteFetchException('That host is not on this server\'s allowed list.');
            }

            // An explicit allowlist is a statement of trust, so it also permits
            // internal hosts, and there is nothing to pin.
            return null;
        }

        if (config('filament-mcp.remote_files.allow_private_networks', false)) {
            return null;
        }

        $ips = $this->resolve($host);

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new RemoteFetchException(
                    'That URL resolves to a private or reserved address, which is not allowed.',
                );
            }
        }

        // Every address checked out. Pin the connection to the first so the name
        // cannot be re-resolved to something else.
        return $ips[0];
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    protected function matchesAnyHost(string $host, array $allowedHosts): bool
    {
        $host = strtolower($host);

        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if ($host === $allowed || Str::is($allowed, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address the host resolves to, since a name with both a public and a
     * private record must fail.
     *
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $key => $type) {
            $records = @dns_get_record($host, $type) ?: [];

            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;

                if ($ip !== null) {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            throw new RemoteFetchException('That host could not be resolved.');
        }

        return $ips;
    }

    protected function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    protected function getMaxBytes(): int
    {
        return ((int) config('filament-mcp.remote_files.max_size', 12288)) * 1024;
    }

    protected function guessName(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $name = basename($path);

        return $name === '' ? null : $name;
    }

    protected function formatBytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
