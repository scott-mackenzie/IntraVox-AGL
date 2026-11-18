<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Support;

use OCA\IntraVox\Service\Cache\PageCacheService;
use OCA\IntraVox\Service\GroupFolders\GroupFoldersGateway;
use OCA\IntraVox\Service\PageIndexService;
use OCA\IntraVox\Service\SetupService;
use OCA\IntraVox\Service\SovereignBundle;
use OCA\IntraVox\Service\SovereignPortalSync;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;

/** An isolated bundle on disk and mutable native Files interface doubles in memory. */
final class SovereignSyncFixture {
    public SovereignBundle $bundle;
    public SovereignPortalSync $sync;
    public SetupService $setup;
    public IConfig $config;
    public IAppData $appData;
    public PageCacheService $pageCache;
    public PageIndexService $index;
    public GroupFoldersGateway $acl;
    public ICacheFactory $cacheFactory;
    public ILockingProvider $locks;
    public ISimpleFolder $backups;
    public ISimpleFolder $snapshot;
    public array $files;
    public array $nodes = [];
    public array $contents = [];
    public array $writes = [];
    public array $events = [];
    public array $cacheNamespaces = [];
    public array $snapshots = [];
    public string $marker = '';
    public string $directory;
    public bool $missingBackups = false;
    private \Closure $mock;

    public function __construct(callable $mock) {
        $this->mock = \Closure::fromCallable($mock);
        $this->directory = sys_get_temp_dir() . '/intravox-unit-' . bin2hex(random_bytes(8));
        $this->files = [
            '_resources/icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'footer.json' => '{"title":"Footer"}',
            'home.json' => '{"uniqueId":"unit-home","title":"Gold Master"}',
            'navigation.json' => '{"items":[]}',
            'sovereign-excom/excom.json' => '{"title":"Private executive workspace"}',
            'sovereign-news/group/group.json' => '{"title":"Group announcement"}',
        ];
        foreach ($this->files as $path => $content) {
            $destination = $this->directory . '/demo-data/sovereign-365/en/' . $path;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0700, true);
            }
            file_put_contents($destination, $content);
        }
        $apps = $mock(IAppManager::class);
        $apps->method('getAppPath')->with('intravox')->willReturn($this->directory);
        $this->bundle = new SovereignBundle($apps);
        $this->setup = $mock(SetupService::class);
        $this->setup->method('getGroupFolderId')->willReturn(57);
        $this->config = $mock(IConfig::class);
        $this->config->method('getAppValue')->with('intravox', 'sovereign_seed_hash', '')
            ->willReturnCallback(fn () => $this->marker);
        $this->config->method('setAppValue')->willReturnCallback(function ($app, $key, $hash): void {
            if ($app !== 'intravox' || $key !== 'sovereign_seed_hash') {
                throw new \LogicException('Unexpected seed marker destination');
            }
            $this->events[] = 'marker';
            $this->marker = $hash;
        });
        $this->pageCache = $mock(PageCacheService::class);
        $this->index = $mock(PageIndexService::class);
        $this->acl = $mock(GroupFoldersGateway::class);
        $this->locks = $mock(ILockingProvider::class);
        $this->appData = $mock(IAppData::class);
        $this->backups = $mock(ISimpleFolder::class);
        $this->snapshot = $mock(ISimpleFolder::class);
        $this->appData->method('getFolder')->with('backups')->willReturnCallback(function () {
            if ($this->missingBackups) {
                throw new NotFoundException('No snapshots yet');
            }
            return $this->backups;
        });
        $this->appData->method('newFolder')->with('backups')->willReturn($this->backups);
        $this->backups->method('newFolder')->willReturnCallback(function ($name) {
            $this->events[] = 'snapshot:' . $name;
            return $this->snapshot;
        });
        $this->snapshot->method('newFile')->willReturnCallback(function ($name, $content) use ($mock) {
            if ($name !== 'files.json') {
                throw new \LogicException('Unexpected private snapshot file');
            }
            $this->snapshots[] = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return $mock(ISimpleFile::class);
        });
        $this->cacheFactory = $mock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturnCallback(function ($namespace) use ($mock) {
            $this->cacheNamespaces[] = $namespace;
            $cache = $mock(ICache::class);
            $cache->method('clear')->willReturnCallback(function () use ($namespace): void {
                $this->events[] = 'clear:' . $namespace;
            });
            return $cache;
        });
        $this->folder('');
        $this->sync = new SovereignPortalSync($this->bundle, $this->setup, $this->config,
            $this->appData, $this->pageCache, $this->index, $this->acl, $this->cacheFactory, $this->locks);
    }

    public function root(): Folder {
        return $this->nodes[''];
    }

    public function seedMatching(): void {
        foreach ($this->files as $path => $content) {
            $this->existing('en/' . $path, $content);
        }
        $this->marker = $this->bundle->hash($this->files);
    }

    public function existing(string $path, string $content): File {
        $this->folder(dirname($path));
        $file = ($this->mock)(File::class);
        $file->method('getContent')->willReturnCallback(fn () => $this->contents[$path]);
        $file->method('putContent')->willReturnCallback(function ($value) use ($path): void {
            $this->events[] = 'write:' . $path;
            $this->writes[] = $path;
            $this->contents[$path] = $value;
        });
        $this->contents[$path] = $content;
        $this->nodes[$path] = $file;
        return $file;
    }

    public function folder(string $path): Folder {
        if (isset($this->nodes[$path])) {
            return $this->nodes[$path];
        }
        if ($path !== '') {
            $parent = dirname($path);
            $this->folder($parent === '.' ? '' : $parent);
        }
        $folder = ($this->mock)(Folder::class);
        $key = static fn (string $relative) => trim($path . '/' . $relative, '/');
        $folder->method('getId')->willReturn(100 + count($this->nodes));
        $folder->method('nodeExists')->willReturnCallback(fn ($relative) => isset($this->nodes[$key($relative)]));
        $folder->method('get')->willReturnCallback(function ($relative) use ($key) {
            return $this->nodes[$key($relative)] ?? throw new NotFoundException($relative);
        });
        $folder->method('newFolder')->willReturnCallback(function ($relative) use ($key) {
            $this->events[] = 'mkdir:' . $key($relative);
            return $this->folder($key($relative));
        });
        $folder->method('newFile')->willReturnCallback(fn ($relative) => $this->existing($key($relative), ''));
        $this->nodes[$path] = $folder;
        return $folder;
    }

    public function close(): void {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
    }
}
