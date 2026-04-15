<?php

declare(strict_types=1);

namespace SuperKernelTests\ComposerPackageRegistry;

use JsonException;
use PHPUnit\Framework\TestCase;
use SuperKernel\ComposerPackageRegistry\ComposerPackageRegistryLoader;
use SuperKernel\ComposerPackageRegistry\Contract\PackageInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageRegistryInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageTypeInterface;
use SuperKernel\ComposerPackageRegistry\Internal\InstalledPackage;
use SuperKernel\RuntimeContext\RuntimeContext;
use function array_map;
use function count;
use function file_get_contents;
use function is_array;
use function json_decode;
use function sort;
use function str_replace;
use function trim;

final class ComposerPackageRegistryLoaderTest extends TestCase
{
    private string $projectRoot;

    /**
     * @var array<string, mixed>
     */
    private array $composerJson;

    /**
     * @var array<string, mixed>
     */
    private array $composerLock;

    private ComposerPackageRegistryLoader $loader;

    /**
     * @return void
     * @throws JsonException
     */
    protected function setUp(): void
    {
        $this->projectRoot = RuntimeContext::getContext()->rootPath();
        $this->composerJson = $this->readJson($this->projectRoot . '/composer.json');
        $this->composerLock = $this->readJson($this->projectRoot . '/composer.lock');
        $this->loader = new ComposerPackageRegistryLoader();
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testItLoadsTheCurrentProjectRootPackage(): void
    {
        $registry = $this->loadRegistry();
        $rootPackage = $registry->getRootPackage();

        self::assertSame($this->composerJson['name'], $rootPackage->getName());
        self::assertSame(PackageTypeInterface::ROOT, $rootPackage->getType());
        self::assertSame($this->composerJson['type'], $rootPackage->getRawType());
        self::assertTrue($rootPackage->isRoot());
        self::assertFalse($rootPackage->isDev());
        self::assertSame('.', $rootPackage->getRelativePath());
        self::assertSame($this->composerJson['autoload'], $rootPackage->getAutoload());
        self::assertSame($this->composerJson['autoload-dev'], $rootPackage->getAutoloadDev());
        self::assertSame($this->resolveVendorDirectory(), $registry->getVendorDirectory());
        self::assertSame(
            $this->resolveVendorDirectory() . '/.super-kernel/packages',
            $registry->getCacheDirectory(),
        );
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testItLoadsAllPackagesDeclaredByComposerLock(): void
    {
        $registry = $this->loadRegistry();
        $expectedCount = 1 + count($this->composerLock['packages'] ?? []) + count($this->composerLock['packages-dev'] ?? []);

        self::assertCount($expectedCount, $registry);
        self::assertSame($expectedCount, count($registry->getPackages()));
        self::assertTrue($registry->hasPackage('super-kernel/composer-package-registry'));
        self::assertTrue($registry->hasPackage('super-kernel/runtime-context'));
        self::assertTrue($registry->hasPackage('phpunit/phpunit'));
        self::assertNull($registry->getPackage('vendor/not-installed'));
        self::assertFalse($registry->hasPackage('vendor/not-installed'));
        self::assertTrue($registry->hasDevPackages());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testItCanFilterPackagesByType(): void
    {
        $registry = $this->loadRegistry();

        $rootPackages = $registry->getPackagesByType(PackageTypeInterface::ROOT);
        self::assertCount(1, $rootPackages);
        self::assertSame('super-kernel/composer-package-registry', $rootPackages[0]->getName());

        $libraries = $registry->getPackagesByType(PackageTypeInterface::LIBRARY);
        $libraryNames = array_map(static fn(PackageInterface $package): string => $package->getName(), $libraries);

        self::assertContains('super-kernel/runtime-context', $libraryNames);
        self::assertContains('phpunit/phpunit', $libraryNames);
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testItProvidesDeterministicIterationOrder(): void
    {
        $registry = $this->loadRegistry();
        $iteratedNames = [];

        foreach ($registry as $package) {
            $iteratedNames[] = $package->getName();
        }

        $sortedNames = $iteratedNames;
        sort($sortedNames);

        self::assertSame($sortedNames, $iteratedNames);
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testRootPackageClassMapSeparatesAutoloadAndAutoloadDev(): void
    {
        $rootPackage = $this->loadRegistry()->getRootPackage();

        $autoloadOnly = $rootPackage->getClassMap(false);
        $withDev = $rootPackage->getClassMap();

        self::assertArrayHasKey(
            'SuperKernel\\ComposerPackageRegistry\\ComposerPackageRegistryLoader',
            $autoloadOnly,
        );
        self::assertSame(
            'src/ComposerPackageRegistryLoader.php',
            $autoloadOnly['SuperKernel\\ComposerPackageRegistry\\ComposerPackageRegistryLoader'],
        );
        self::assertArrayNotHasKey(
            'SuperKernelTests\\ComposerPackageRegistry\\ComposerPackageRegistryLoaderTest',
            $autoloadOnly,
        );
        self::assertArrayHasKey(
            'SuperKernelTests\\ComposerPackageRegistry\\ComposerPackageRegistryLoaderTest',
            $withDev,
        );
        self::assertSame(
            'tests/ComposerPackageRegistryLoaderTest.php',
            $withDev['SuperKernelTests\\ComposerPackageRegistry\\ComposerPackageRegistryLoaderTest'],
        );
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testRootPackageFilesCollectionRespectsAutoloadDevBoundary(): void
    {
        $rootPackage = $this->loadRegistry()->getRootPackage();

        self::assertSame([], $rootPackage->getFiles(false));
        self::assertSame([], $rootPackage->getFiles());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testInstalledRuntimeContextPackageIsResolvedFromComposerLock(): void
    {
        $registry = $this->loadRegistry();
        $package = $registry->getPackage('super-kernel/runtime-context');

        self::assertNotNull($package);
        self::assertSame('super-kernel/runtime-context', $package->getName());
        self::assertSame(PackageTypeInterface::LIBRARY, $package->getType());
        self::assertSame('library', $package->getRawType());
        self::assertFalse($package->isRoot());
        self::assertFalse($package->isDev());
        self::assertSame(
            $this->resolveVendorDirectory() . '/super-kernel/runtime-context',
            $package->getRelativePath(),
        );
        self::assertIsString($package->getReference());
        self::assertNotSame('', $package->getReference());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testInstalledDevPackageIsMarkedAsDev(): void
    {
        $registry = $this->loadRegistry();
        $package = $registry->getPackage('phpunit/phpunit');

        self::assertNotNull($package);
        self::assertSame('phpunit/phpunit', $package->getName());
        self::assertTrue($package->isDev());
        self::assertFalse($package->isRoot());
        self::assertSame(
            $this->resolveVendorDirectory() . '/phpunit/phpunit',
            $package->getRelativePath(),
        );
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testItWritesSerializablePackageCachesIntoTheConfiguredDirectory(): void
    {
        $registry = $this->loadRegistry();
        $cacheRoot = $this->projectRoot . '/' . $registry->getCacheDirectory();

        $expectedCacheFiles = [
            'super-kernel/composer-package-registry.cache',
            'super-kernel/runtime-context.cache',
            'phpunit/phpunit.cache',
        ];

        foreach ($expectedCacheFiles as $relativeCacheFile) {
            $cacheFile = $cacheRoot . '/' . $relativeCacheFile;
            self::assertFileExists($cacheFile);

            $serialized = file_get_contents($cacheFile);
            self::assertNotFalse($serialized);
            self::assertNotSame('', $serialized);

            $package = unserialize($serialized, ['allowed_classes' => [InstalledPackage::class]]);
            self::assertInstanceOf(PackageInterface::class, $package);
        }
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testItCanLoadTheCurrentRuntimeProject(): void
    {
        $registry = $this->loader->loadCurrentRuntimeProject();

        self::assertSame($this->composerJson['name'], $registry->getRootPackage()->getName());
        self::assertTrue($registry->hasPackage('super-kernel/runtime-context'));
    }

    /**
     * @return PackageRegistryInterface
     * @throws JsonException
     */
    private function loadRegistry(): PackageRegistryInterface
    {
        return $this->loader->load($this->projectRoot);
    }

    /**
     * @param string $file
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function readJson(string $file): array
    {
        $json = file_get_contents($file);
        self::assertNotFalse($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function resolveVendorDirectory(): string
    {
        $config = is_array($this->composerJson['config'] ?? null)
            ? $this->composerJson['config']
            : [];

        $vendorDirectory = $config['vendor-bin'] ?? $config['vendor-dir'] ?? 'vendor';

        self::assertIsString($vendorDirectory);

        return trim(str_replace('\\', '/', $vendorDirectory), '/');
    }
}
