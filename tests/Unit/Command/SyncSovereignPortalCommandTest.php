<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Command;

use OCA\IntraVox\Command\SyncSovereignPortalCommand;
use OCA\IntraVox\Tests\Support\SovereignSyncFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncSovereignPortalCommandTest extends TestCase {
    private SovereignSyncFixture $fixture;
    private CommandTester $command;

    protected function setUp(): void {
        $this->fixture = new SovereignSyncFixture(fn ($type) => $this->createMock($type));
        $this->command = new CommandTester(new SyncSovereignPortalCommand($this->fixture->sync));
    }

    protected function tearDown(): void {
        $this->fixture->close();
    }

    public function testNoopOutputAndSuccessStatus(): void {
        $f = $this->fixture;
        $f->seedMatching();
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        self::assertSame(0, $this->command->execute([], ['interactive' => false]));
        self::assertSame("Sovereign 365 portal content already up to date\n", $this->command->getDisplay(true));
    }

    public function testChangedFilesSummaryIncludesHashAndPrivateSnapshot(): void {
        $f = $this->fixture;
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        self::assertSame(0, $this->command->execute([], ['interactive' => false]));
        self::assertStringContainsString('Sovereign 365 portal synchronized: 6 files changed; sha256 ' . $f->marker,
            $this->command->getDisplay());
        self::assertStringContainsString('; private snapshot backups/sovereign-', $this->command->getDisplay());
    }

    public function testForceOptionReachesRealSyncAndBypassesNoop(): void {
        $f = $this->fixture;
        $f->seedMatching();
        $f->setup->method('getSharedFolder')->willReturn($f->root());
        self::assertSame(0, $this->command->execute(['--force' => true], ['interactive' => false]));
        self::assertStringContainsString('synchronized: 0 files changed', $this->command->getDisplay());
        self::assertCount(1, $f->snapshots);
    }

    public function testStorageFailureReturnsNonzeroStatus(): void {
        $this->fixture->setup->method('getSharedFolder')->willReturn(null);
        self::assertSame(1, $this->command->execute([], ['interactive' => false]));
        self::assertSame("Sovereign 365 sync failed: Configured IntraVox storage is unavailable\n", $this->command->getDisplay(true));
    }

    public function testThrowableMessageIsEscapedRatherThanInterpretedAsConsoleMarkup(): void {
        $this->fixture->setup->method('getSharedFolder')
            ->willThrowException(new \Error('<info>Do not format this</info>'));
        self::assertSame(1, $this->command->execute([], ['interactive' => false, 'decorated' => true]));
        self::assertStringContainsString('<info>Do not format this</info>', $this->command->getDisplay(true));
        self::assertStringNotContainsString("\033[32m", $this->command->getDisplay());
    }
}
