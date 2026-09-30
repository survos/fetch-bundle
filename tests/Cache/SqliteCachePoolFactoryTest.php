<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Survos\FetchBundle\Cache\SqliteCachePoolFactory;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class SqliteCachePoolFactoryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/fetch-bundle-sqlite-test-' . bin2hex(random_bytes(4)) . '/cache.db';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->dbPath));
    }

    public function testCreatePoolCreatesTheContainingDirectory(): void
    {
        self::assertDirectoryDoesNotExist(\dirname($this->dbPath));

        SqliteCachePoolFactory::createPool($this->dbPath);

        self::assertDirectoryExists(\dirname($this->dbPath));
    }

    public function testCreatedPoolIsAUsableCacheStoringDataAcrossInstances(): void
    {
        $pool = SqliteCachePoolFactory::createPool($this->dbPath);
        self::assertInstanceOf(PdoAdapter::class, $pool);

        $pool->get('key', fn () => 'value');

        // A second pool instance over the same file sees what the first wrote --
        // this is the "disposable, recreatable, survives process restarts" property.
        $second = SqliteCachePoolFactory::createPool($this->dbPath);
        self::assertTrue($second->hasItem('key'));
        self::assertSame('value', $second->get('key', fn () => 'should not be called'));
    }

    public function testDeletingTheFileResetsTheCache(): void
    {
        $pool = SqliteCachePoolFactory::createPool($this->dbPath);
        $pool->get('key', fn () => 'value');
        self::assertFileExists($this->dbPath);

        unlink($this->dbPath);

        $fresh = SqliteCachePoolFactory::createPool($this->dbPath);
        self::assertFalse($fresh->hasItem('key'), 'deleting the sqlite file should behave like a clean, empty cache');
    }

    public function testPoolRunsInWalModeSoReadersDoNotWaitOnWriters(): void
    {
        SqliteCachePoolFactory::createPool($this->dbPath)->get('key', fn () => 'value');

        $mode = (new \PDO('sqlite:' . $this->dbPath))->query('PRAGMA journal_mode')->fetchColumn();
        self::assertSame('wal', $mode);
    }

    public function testValuesAreStoredCompressed(): void
    {
        $html = str_repeat('<p>the same paragraph over and over</p>', 1000);
        $pool = SqliteCachePoolFactory::createPool($this->dbPath);
        $pool->get('key', fn () => $html);

        $stored = (new \PDO('sqlite:' . $this->dbPath))->query('SELECT LENGTH(item_data) FROM cache_items')->fetchColumn();
        self::assertLessThan(\strlen($html) / 10, (int) $stored);
        self::assertSame($html, $pool->get('key', fn () => 'should not be called'));
    }

    public function testEntriesWrittenBeforeCompressionStillRead(): void
    {
        mkdir(\dirname($this->dbPath), 0775, true);
        (new PdoAdapter('sqlite:' . $this->dbPath))->get('key', fn () => 'plain value');

        self::assertSame('plain value', SqliteCachePoolFactory::createPool($this->dbPath)->get('key', fn () => 'should not be called'));
    }

    public function testPruneDeletesExpiredEntries(): void
    {
        $pool = SqliteCachePoolFactory::createPool($this->dbPath);
        $pool->get('short', function ($item) { $item->expiresAfter(1); return 'x'; });
        $pool->get('forever', fn () => 'y');
        sleep(2);

        self::assertTrue($pool->prune());
        $left = (new \PDO('sqlite:' . $this->dbPath))->query('SELECT COUNT(*) FROM cache_items')->fetchColumn();
        self::assertSame(1, (int) $left);
    }
}
