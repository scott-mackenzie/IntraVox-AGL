<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Tests\Support\SovereignSyncFixture;
use OCP\Files\File;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class SovereignPortalSyncTest extends TestCase {
    private SovereignSyncFixture $fixture;

    protected function setUp(): void {
        $this->fixture = new SovereignSyncFixture(fn ($type) => $this->createMock($type));
    }

    protected function tearDown(): void {
        $this->fixture->close();
    }

    private function expectLock(): void {
        $this->fixture->locks->expects(self::once())->method('acquireLock')
            ->with('intravox/sovereign-portal-sync', ILockingProvider::LOCK_EXCLUSIVE);
        $this->fixture->locks->expects(self::once())->method('releaseLock')
            ->with('intravox/sovereign-portal-sync', ILockingProvider::LOCK_EXCLUSIVE);
    }

    public function testInitialSyncCreatesPrivateBackupNestedFilesAndFlushesBeforeMarker(): void {
        $f = $this->fixture;
        $this->expectLock();
        $f->missingBackups = true;
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $f->appData->expects(self::once())->method('newFolder')->with('backups');
        $f->snapshot->expects(self::once())->method('newFile')->with('files.json', '[]');
        $f->acl->expects(self::once())->method('protectExecutiveFolder')
            ->with(57, self::greaterThan(0))->willReturnCallback(function () use ($f): void {
                self::assertSame([], $f->writes, 'ACL must precede private content writes');
                $f->events[] = 'acl';
            });
        $f->pageCache->expects(self::once())->method('clearRequest');
        $f->pageCache->expects(self::once())->method('clearExpensive')->willReturn(true);
        $f->index->expects(self::once())->method('clearLanguage')->with('en');
        $result = $f->sync->sync();
        self::assertFalse($result['noop']);
        self::assertSame(count($f->files), $result['changed']);
        self::assertMatchesRegularExpression('~^backups/sovereign-\d{8}T\d{6}Z-[a-f0-9]{16}/files.json$~', $result['backup']);
        self::assertSame($f->bundle->hash($f->files), $result['hash']);
        self::assertSame($result['hash'], $f->marker);
        self::assertSame([[]], $f->snapshots);
        self::assertContains('mkdir:en/sovereign-news/group', $f->events);
        self::assertSame(['intravox-pages', 'intravox-permissions', 'intravox-people'], $f->cacheNamespaces);
        foreach ($f->cacheNamespaces as $namespace) {
            self::assertContains('clear:' . $namespace, $f->events);
        }
        self::assertSame('marker', end($f->events));
        self::assertFalse(isset($f->nodes['en/backups']));
        foreach ($f->files as $path => $content) {
            self::assertSame($content, $f->contents['en/' . $path]);
        }
    }

    public function testMatchingHashAndLiveBytesAreAnIdempotentNoop(): void {
        $f = $this->fixture;
        $this->expectLock();
        $f->seedMatching();
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $f->appData->expects(self::never())->method('getFolder');
        $f->acl->expects(self::never())->method('protectExecutiveFolder');
        $f->config->expects(self::never())->method('setAppValue');
        $f->pageCache->expects(self::never())->method('clearExpensive');
        $result = $f->sync->sync();
        self::assertSame(['changed' => 0, 'hash' => $f->marker, 'backup' => null, 'noop' => true], $result);
        self::assertSame([], $f->writes);
        self::assertSame([], $f->cacheNamespaces);
    }

    public function testForceReappliesMatchingHashWithoutRewritingUnchangedFiles(): void {
        $f = $this->fixture;
        $f->seedMatching();
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $f->appData->expects(self::never())->method('newFolder');
        $f->acl->expects(self::once())->method('protectExecutiveFolder');
        $result = $f->sync->sync(true);
        self::assertFalse($result['noop']);
        self::assertSame(0, $result['changed']);
        self::assertSame([$f->files], $f->snapshots);
        self::assertNotNull($result['backup']);
        self::assertSame([], $f->writes);
        self::assertCount(3, $f->cacheNamespaces);
    }

    public function testMatchingMarkerCannotHideModifiedAndMissingLiveFiles(): void {
        $f = $this->fixture;
        $f->seedMatching();
        $f->contents['en/home.json'] = '{"title":"Local edit"}';
        unset($f->nodes['en/footer.json'], $f->contents['en/footer.json']);
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $result = $f->sync->sync();
        self::assertFalse($result['noop']);
        self::assertSame(2, $result['changed']);
        self::assertSame(['en/footer.json', 'en/home.json'], $f->writes);
        self::assertSame('{"title":"Local edit"}', $f->snapshots[0]['home.json']);
        self::assertArrayNotHasKey('footer.json', $f->snapshots[0]);
    }

    public function testUnavailableStorageReleasesLockWithoutSavingMarker(): void {
        $this->expectLock();
        $this->fixture->setup->method('getSharedFolder')->willReturn(null);
        $this->fixture->config->expects(self::never())->method('setAppValue');
        $this->expectExceptionMessage('Configured IntraVox storage is unavailable');
        $this->fixture->sync->sync();
    }

    public function testNonFileExistingDestinationFailsBeforeBackupAndReleasesLock(): void {
        $f = $this->fixture;
        $this->expectLock();
        $f->folder('en/home.json');
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $f->appData->expects(self::never())->method('getFolder');
        $this->expectExceptionMessage('Bundle destination is not a file: home.json');
        $f->sync->sync();
    }

    public function testNonFolderLanguagePathReleasesLock(): void {
        $f = $this->fixture;
        $this->expectLock();
        $f->nodes['en'] = $this->createMock(File::class);
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $this->expectExceptionMessage('Bundle destination is not a folder: en');
        $f->sync->sync();
    }

    public function testNonFolderNestedTargetFailsAfterSnapshotWithoutCommittingMarker(): void {
        $f = $this->fixture;
        $this->expectLock();
        $f->folder('en');
        $f->nodes['en/_resources'] = $this->createMock(File::class);
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $f->config->expects(self::never())->method('setAppValue');
        $this->expectExceptionMessage('Bundle destination is not a folder: _resources');
        $f->sync->sync();
    }

    public function testWrongNewFileNodeIsRejectedAndLockReleased(): void {
        $f = $this->fixture;
        $this->expectLock();
        $language = $this->createMock(\OCP\Files\Folder::class);
        $language->method('nodeExists')->willReturn(false);
        $language->method('newFile')->willReturn($f->root());
        $f->nodes['en'] = $language;
        // The first sorted file descends into _resources; that directory is the
        // language double too, so newFile returns a Folder rather than a File.
        $language->method('newFolder')->willReturn($language);
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        $this->expectExceptionMessage('Bundle destination is not a file:');
        $f->sync->sync();
    }
}
