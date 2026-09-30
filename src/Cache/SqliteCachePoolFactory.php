<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Cache;

use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
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

    /** Seconds a writer waits for another process's write lock before giving up. */
    private const BUSY_TIMEOUT = 5;

    /**
     * DI factory (runs lazily, at first use -- not at container compile time): ensures the
     * containing directory exists before opening the SQLite file.
     *
     * Several processes share this file (web requests plus messenger workers), so it runs in WAL
     * mode -- readers never wait on a writer -- and a writer that does meet another writer waits
     * up to BUSY_TIMEOUT instead of failing with "database is locked". Values are deflated: they
     * are mostly HTML, which shrinks to a fraction of its size. Entries written before compression
     * was on still read fine (DeflateMarshaller falls back to the raw value).
     */
    public static function createPool(string $dbPath): PdoAdapter
    {
        $dir = \dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, self::BUSY_TIMEOUT);
        // WAL is recorded in the file, so this only writes the first time. Switching takes an
        // exclusive lock that ignores the busy timeout; if another process is mid-switch, it wins.
        if ('wal' !== $pdo->query('PRAGMA journal_mode')->fetchColumn()) {
            try {
                $pdo->exec('PRAGMA journal_mode = WAL');
            } catch (\PDOException) {
            }
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');

        $pool = new PdoAdapter($pdo, marshaller: new DeflateMarshaller(new DefaultMarshaller()));

        // PdoAdapter creates its table on the first failed write, which loses that write when
        // several processes meet a brand-new file at once. Create it here; losing the race is fine.
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'cache_items'")->fetchColumn()) {
            try {
                $pool->createTable();
            } catch (\PDOException) {
            }
        }

        return $pool;
    }
}
