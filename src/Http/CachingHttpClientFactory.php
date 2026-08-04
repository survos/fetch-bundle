<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Http;

use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\CachingHttpClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers a Symfony RFC 9111 CachingHttpClient (FilesystemTagAwareAdapter-backed) decorating an
 * existing HTTP client service, for a bundle to call from its own loadExtension().
 *
 * Extracted after the same ~15-line block was independently written three times: correctly in
 * loc-bundle and omeka-bundle, and incorrectly in scraper-bundle's CacheScraperService (backed by
 * HttpKernel\HttpCache\Store — a reverse-proxy response cache, not a PSR cache pool — hence its
 * own "doesn't appear to be working" comment). One correct implementation instead of N
 * almost-correct ones; each bundle still owns its own identity (user-agent, API key, base_uri,
 * timeout, cache_enabled/cache_max_ttl config) and decorates this with it — this factory only
 * owns the generic wiring mechanics.
 *
 * Usage, from a bundle's own loadExtension():
 *
 *     $cachingClientId = CachingHttpClientFactory::register(
 *         container: $container,
 *         idPrefix: 'survos_poi',
 *         decoratedClientId: 'poi.nominatim.client',
 *         cacheDir: '%kernel.cache_dir%/poi_http',
 *         maxTtl: $config['cache_max_ttl'],
 *         baseUri: $config['base_url'], // only when decorating a SCOPED client — see below
 *     );
 *     $services->set(NominatimPoiClient::class)->arg('$http', service($cachingClientId));
 *
 * Deliberately NOT covering: whether caching is enabled at all (the caller's own boolean config
 * node — just don't call register() when it's false, use the decorated client id directly instead)
 * and request timeout (a property of the decorated client's own scoped-client config, orthogonal
 * to caching — set it there, not here).
 */
final class CachingHttpClientFactory
{
    /**
     * @param string $idPrefix service id prefix, e.g. 'survos_poi' — registers "{idPrefix}.http_cache"
     *     and "{idPrefix}.caching_client"
     * @param string $decoratedClientId service id of the HTTP client being cache-wrapped
     * @param string $cacheDir filesystem cache directory, e.g. '%kernel.cache_dir%/poi_http'
     * @param int $maxTtl upper bound on cache lifetime even if the origin sends a longer max-age
     * @param string|null $baseUri REQUIRED when $decoratedClientId is a scoped client: CachingHttpClient
     *     resolves URLs itself before delegating and does NOT inherit the decorated client's own
     *     base_uri default option, so a relative request throws "scheme is missing" without this.
     *     Omit (null) when decorating an already-absolute-URL client (e.g. the default 'http_client').
     * @return string the registered caching-client service id ("{idPrefix}.caching_client")
     */
    public static function register(
        ContainerConfigurator $container,
        string $idPrefix,
        string $decoratedClientId,
        string $cacheDir,
        int $maxTtl,
        ?string $baseUri = null,
    ): string {
        $services = $container->services();

        $cacheId = $idPrefix . '.http_cache';
        $services->set($cacheId, FilesystemTagAwareAdapter::class)
            ->args([$idPrefix, $maxTtl, $cacheDir])
            ->public(false);

        $clientId = $idPrefix . '.caching_client';
        $services->set($clientId, CachingHttpClient::class)
            ->args([
                service($decoratedClientId),
                service($cacheId),
                null !== $baseUri ? ['base_uri' => $baseUri] : [],
                true,
                $maxTtl,
            ])
            ->public(false);

        return $clientId;
    }
}
