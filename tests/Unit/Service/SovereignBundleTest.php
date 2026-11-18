<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\SovereignBundle;
use OCA\IntraVox\Tests\Support\SovereignSyncFixture;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SovereignBundleTest extends TestCase {
    private ?SovereignSyncFixture $fixture = null;

    protected function tearDown(): void {
        $this->fixture?->close();
    }

    private function isolated(): SovereignBundle {
        $this->fixture = new SovereignSyncFixture(fn ($type) => $this->createMock($type));
        return $this->fixture->bundle;
    }

    public function testPackagedBundleIsPortableAndHashIsStable(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppPath')->with('intravox')->willReturn(dirname(__DIR__, 3));
        $bundle = new SovereignBundle($apps);
        $files = $bundle->files();
        self::assertCount(23, $files);
        self::assertSame($bundle->hash($files), $bundle->hash(array_reverse($files, true)));
        $changed = $files;
        $changed['home.json'] .= "\n";
        self::assertNotSame($bundle->hash($files), $bundle->hash($changed));
        self::assertNotSame($bundle->hash(['ab' => 'c']), $bundle->hash(['a' => 'bc']));
        self::assertSame('page-3429f149-dd7c-492a-8a4a-dab3ff83d511', json_decode($files['home.json'], true)['uniqueId']);
        foreach ($files as $path => $content) {
            self::assertDoesNotMatchRegularExpression('~\.replit\.dev|localhost|__groupfolders/1~i', $content, $path);
        }
    }

    public function testMissingBundleFailsExplicitly(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppPath')->willReturn('/nonexistent-intravox-release');
        $this->expectException(\RuntimeException::class);
        (new SovereignBundle($apps))->files();
    }

    public function testMissingRequiredRootDocumentFailsExplicitly(): void {
        $bundle = $this->isolated();
        unlink($this->fixture->directory . '/demo-data/sovereign-365/en/footer.json');
        $this->expectExceptionMessage('Missing required bundle file: footer.json');
        $bundle->files();
    }

    public static function unsafeContents(): array {
        return [
            'sandbox domain' => ['https://example.replit.dev/path'],
            'localhost' => ['https://localhost/path'],
            'sandbox storage' => ['__groupfolders/1/files/en'],
        ];
    }

    #[DataProvider('unsafeContents')]
    public function testSandboxReferencesAreRejectedBeforeStorageIsTouched(string $content): void {
        $bundle = $this->isolated();
        file_put_contents($this->fixture->directory . '/demo-data/sovereign-365/en/_resources/icon.svg', $content);
        $this->expectExceptionMessage('Unsafe Gold Master bundle file:');
        $bundle->files();
    }

    public function testUnsupportedExtensionAndUnsafePathAreRejected(): void {
        $bundle = $this->isolated();
        file_put_contents($this->fixture->directory . '/demo-data/sovereign-365/en/unsafe name.txt', 'content');
        $this->expectExceptionMessage('Unsafe Gold Master bundle file: unsafe name.txt');
        $bundle->files();
    }

    public function testMalformedJsonCannotBeSynced(): void {
        $bundle = $this->isolated();
        file_put_contents($this->fixture->directory . '/demo-data/sovereign-365/en/home.json', '{invalid');
        $this->expectException(\JsonException::class);
        $bundle->files();
    }

    public function testSymlinkCannotEscapeThePackagedBundle(): void {
        $bundle = $this->isolated();
        $root = $this->fixture->directory . '/demo-data/sovereign-365/en';
        symlink($root . '/home.json', $root . '/linked.json');
        $this->expectExceptionMessage('Unsupported bundle entry');
        $bundle->files();
    }

    public function testUnreadableBundleFileFailsExplicitlyWithoutPhpunitWarning(): void {
        $bundle = $this->isolated();
        $file = $this->fixture->directory . '/demo-data/sovereign-365/en/home.json';
        chmod($file, 0000);
        // Exercise the production false-return guard without letting the expected
        // filesystem warning become an unrelated PHPUnit warning.
        set_error_handler(static fn (int $severity) => $severity === E_WARNING);
        try {
            $this->expectExceptionMessage('Cannot read bundle file: home.json');
            $bundle->files();
        } finally {
            restore_error_handler();
            chmod($file, 0600);
        }
    }
}
