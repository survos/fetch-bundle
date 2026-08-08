<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates an HttpClient (typically a scoped client already configured with a fixed `base_uri`
 * in framework.yaml) so that requests to a `.wip` base_uri automatically route through Symfony
 * CLI's local proxy — see {@see WipProxy} for why this exists.
 *
 * The `.wip`-ness check runs once at construction against $baseUri (the scoped client's own
 * config value, e.g. `%env(SSAI_HUB_URL)%`), not per-request against $url — a scoped client's
 * requests pass a path relative to its baked-in base_uri, so the host isn't reliably visible in
 * $url itself.
 *
 * Usage, decorating a scoped client from an app's own services.yaml (bundles wanting the same
 * thing from their own loadExtension() should register this directly rather than adding a
 * factory — the wiring here is three lines):
 *
 *     Survos\FetchBundle\Http\WipProxyHttpClient:
 *         decorates: 'ssai.hub'
 *         arguments:
 *             $client: '@.inner'
 *             $baseUri: '%env(SSAI_HUB_URL)%'
 */
final class WipProxyHttpClient implements HttpClientInterface, ResetInterface
{
    private readonly bool $applies;

    public function __construct(
        private HttpClientInterface $client,
        ?string $baseUri = null,
        private readonly string $proxy = WipProxy::DEFAULT_PROXY,
    ) {
        $this->applies = WipProxy::appliesTo($baseUri);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->applies && !isset($options['proxy'])) {
            $options['proxy'] = $this->proxy;
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
