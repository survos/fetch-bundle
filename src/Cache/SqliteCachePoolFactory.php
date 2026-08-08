<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Cache;

use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Registers a disposable, file-based Symfony cache pool (a Symfony\Component\Cache\Adapter\PdoAdapter
 * over a dedicated SQLite file) for a bundle/app to call from its own loadExtension().
 *
 * This is the counterpart to CachingHttpClientFactory for callers that want the OPPOSITE caching
 * philosophy: instead of obeying the origin's Cache-Control/Expires headers (RFC 9111), the CALLER
 * decides how long a response stays cached, independent of what -- or whether -- the remote server
 * sends caching headers at all. That's the right fit for scraping sites that routinely send
 * no-store or no caching headers whatsoever, but where the app still wants an aggressive, durable
 * cache keyed by URL (see PersistentFetcher).
 *
 * The backing file is a single disposable SQLite database: delete it and PdoAdapter recreates the
 * table on first write. That makes it trivial to inspect (`sqlite3 file.db`), back up, or blow away
 * for a clean re-scrape, without depending on Redis/Memcached or the app's own Doctrine database.
 *
 * Usage, from a bundle's own loadExtension():
 *
 *     $poolId = SqliteCachePoolFactory::register(
 *         container: $container,
 *         idPrefix: 'survos_news_fetch',
 *         dbPath: '%kernel.project_dir%/var/data/fetch_cache.db',
 *     );
 *     $services->set(MyFetcher::class)->arg('$cache', service($poolId));
 */
final class SqliteCachePoolFactory
{
    /**
     * @param string $idPrefix service id prefix, e.g. 'survos_news_fetch' -- registers "{idPrefix}.sqlite_cache"
     * @param string $dbPath   filesystem path to the SQLite file, e.g. '%kernel.project_dir%/var/data/fetch_cache.db'
     *     (deliberately NOT under %kernel.cache_dir% by default -- that directory gets wiped by
     *     cache:clear/deploys, which would defeat a cache meant to persist across those)
     * @return string the registered pool service id ("{idPrefix}.sqlite_cache")
     */
    public static function register(
        ContainerConfigurator $container,
        string $idPrefix,
        string $dbPath,
    ): string {
        $poolId = $idPrefix . '.sqlite_cache';

        $container->services()
            ->set($poolId, PdoAdapter::class)
            ->factory([self::class, 'createPool'])
            ->args([$dbPath])
            ->public(false);

        return $poolId;
    }

    /**
     * DI factory (runs lazily, at first use -- not at container compile time): ensures the
     * containing directory exists before PdoAdapter opens the SQLite file.
     */
    public static function createPool(string $dbPath): PdoAdapter
    {
        $dir = \dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // PdoAdapter wants a real PDO DSN ("sqlite:/path"), not a Doctrine DBAL URL ("sqlite:///path").
        return new PdoAdapter('sqlite:' . $dbPath);
    }
}
