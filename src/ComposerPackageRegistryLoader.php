<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SuperKernel\ComposerPackageRegistry\Contract\PackageInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageRegistryInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageRegistryLoaderInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageTypeInterface;
use SuperKernel\ComposerPackageRegistry\Internal\InstalledPackage;
use SuperKernel\ComposerPackageRegistry\Internal\PackageCacheRepository;
use SuperKernel\ComposerPackageRegistry\Internal\PackageRegistry;
use SuperKernel\ComposerPackageRegistry\Internal\PackageScanner;
use SuperKernel\ComposerPackageRegistry\Internal\RuntimePathToolkit;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function realpath;
use function sha1;
use function sprintf;
use function trim;

final readonly class ComposerPackageRegistryLoader implements PackageRegistryLoaderInterface
{
    private PackageScanner $packageScanner;
    private RuntimePathToolkit $paths;

    public function __construct(?PackageScanner $packageScanner = null, ?RuntimePathToolkit $paths = null)
    {
        $this->packageScanner = $packageScanner ?? new PackageScanner();
        $this->paths = $paths ?? new RuntimePathToolkit();
    }

    /**
     * @param string $projectRoot
     * @return PackageRegistryInterface
     * @throws JsonException
     */
    public function load(string $projectRoot): PackageRegistryInterface
    {
        $projectRoot = realpath($projectRoot);

        if ($projectRoot === false || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('The given project root path is invalid.');
        }

        $projectRoot = $this->paths->normalizeAbsolutePath($projectRoot);

        $composerJsonPath = $projectRoot . '/composer.json';
        $composerLockPath = $projectRoot . '/composer.lock';

        if (!is_file($composerJsonPath)) {
            throw new RuntimeException(sprintf('composer.json not found in "%s".', $projectRoot));
        }

        if (!is_file($composerLockPath)) {
            throw new RuntimeException(sprintf('composer.lock not found in "%s".', $projectRoot));
        }

        $rootConfig = $this->readJsonFile($composerJsonPath);
        $lockConfig = $this->readJsonFile($composerLockPath);

        $vendorDirectory = $this->resolveVendorDirectory($rootConfig);
        $cacheDirectory = $this->paths->joinRelativePath($vendorDirectory, '.super-kernel/packages');
        $cacheRepository = new PackageCacheRepository($projectRoot, $cacheDirectory);

        $packages = [];

        $rootPackageName = is_string($rootConfig['name'] ?? null)
            ? $rootConfig['name']
            : 'root-package';

        $rootReference = $this->resolveRootReference($rootConfig, $lockConfig);

        $packages[] = $cacheRepository->loadOrRefresh(
            $rootPackageName,
            $rootReference,
            function () use ($projectRoot, $rootPackageName, $rootConfig, $rootReference): PackageInterface {
                return $this->buildPackage(
                    projectRoot: $projectRoot,
                    name: $rootPackageName,
                    type: PackageTypeInterface::ROOT,
                    rawType: is_string($rootConfig['type'] ?? null) ? $rootConfig['type'] : null,
                    reference: $rootReference,
                    dev: false,
                    composerConfig: $rootConfig,
                    relativePath: '.',
                    autoload: is_array($rootConfig['autoload'] ?? null) ? $rootConfig['autoload'] : [],
                    autoloadDev: is_array($rootConfig['autoload-dev'] ?? null) ? $rootConfig['autoload-dev'] : [],
                );
            }
        );

        foreach ($this->normalizeLockPackages($lockConfig['packages'] ?? []) as $packageConfig) {
            $packages[] = $this->loadInstalledPackage(
                projectRoot: $projectRoot,
                vendorDirectory: $vendorDirectory,
                packageConfig: $packageConfig,
                isDev: false,
                cacheRepository: $cacheRepository,
            );
        }

        foreach ($this->normalizeLockPackages($lockConfig['packages-dev'] ?? []) as $packageConfig) {
            $packages[] = $this->loadInstalledPackage(
                projectRoot: $projectRoot,
                vendorDirectory: $vendorDirectory,
                packageConfig: $packageConfig,
                isDev: true,
                cacheRepository: $cacheRepository,
            );
        }

        /** @var PackageInterface $rootPackage */
        $rootPackage = $packages[0];

        return new PackageRegistry(
            rootPackage: $rootPackage,
            vendorDirectory: $vendorDirectory,
            cacheDirectory: $cacheDirectory,
            packages: $packages,
        );
    }

    /**
     * @param array<string, mixed> $packageConfig
     */
    private function loadInstalledPackage(
        string                 $projectRoot,
        string                 $vendorDirectory,
        array                  $packageConfig,
        bool                   $isDev,
        PackageCacheRepository $cacheRepository,
    ): PackageInterface
    {
        $name = (string)($packageConfig['name'] ?? '');

        if ($name === '') {
            throw new RuntimeException('A package in composer.lock does not contain a valid "name".');
        }

        $rawType = is_string($packageConfig['type'] ?? null) ? $packageConfig['type'] : null;
        $reference = $this->resolvePackageReference($packageConfig);
        $relativePath = $this->resolvePackageRelativePath($vendorDirectory, $packageConfig);

        return $cacheRepository->loadOrRefresh(
            $name,
            $reference,
            function () use (
                $projectRoot,
                $name,
                $rawType,
                $reference,
                $isDev,
                $packageConfig,
                $relativePath,
            ): PackageInterface {
                return $this->buildPackage(
                    projectRoot: $projectRoot,
                    name: $name,
                    type: $this->normalizePackageType($rawType, false),
                    rawType: $rawType,
                    reference: $reference,
                    dev: $isDev,
                    composerConfig: $packageConfig,
                    relativePath: $relativePath,
                    autoload: is_array($packageConfig['autoload'] ?? null) ? $packageConfig['autoload'] : [],
                    autoloadDev: is_array($packageConfig['autoload-dev'] ?? null) ? $packageConfig['autoload-dev'] : [],
                );
            }
        );
    }

    /**
     * @param array<string, mixed> $composerConfig
     * @param array<string, mixed> $autoload
     * @param array<string, mixed> $autoloadDev
     */
    private function buildPackage(
        string  $projectRoot,
        string  $name,
        string  $type,
        ?string $rawType,
        ?string $reference,
        bool    $dev,
        array   $composerConfig,
        string  $relativePath,
        array   $autoload,
        array   $autoloadDev,
    ): InstalledPackage
    {
        $scanResult = $this->packageScanner->scan(
            projectRoot: $projectRoot,
            packageRelativePath: $relativePath,
            autoload: $autoload,
            autoloadDev: $autoloadDev,
        );

        return new InstalledPackage(
            name: $name,
            type: $type,
            rawType: $rawType,
            reference: $reference,
            dev: $dev,
            composerConfig: $composerConfig,
            relativePath: $relativePath,
            autoload: $autoload,
            autoloadDev: $autoloadDev,
            classMap: $scanResult['classMap'],
            devClassMap: $scanResult['devClassMap'],
            files: $scanResult['files'],
            devFiles: $scanResult['devFiles'],
        );
    }

    /**
     * @param array<string, mixed> $rootConfig
     * @param array<string, mixed> $lockConfig
     * @return string
     * @throws JsonException
     */
    private function resolveRootReference(array $rootConfig, array $lockConfig): string
    {
        $normalized = [
            'name' => $rootConfig['name'] ?? null,
            'type' => $rootConfig['type'] ?? null,
            'autoload' => $rootConfig['autoload'] ?? [],
            'autoload-dev' => $rootConfig['autoload-dev'] ?? [],
            'content-hash' => $lockConfig['content-hash'] ?? null,
        ];

        return sha1((string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $packageConfig
     */
    private function resolvePackageReference(array $packageConfig): ?string
    {
        $distReference = $packageConfig['dist']['reference'] ?? null;
        if (is_string($distReference) && $distReference !== '') {
            return $distReference;
        }

        $sourceReference = $packageConfig['source']['reference'] ?? null;
        if (is_string($sourceReference) && $sourceReference !== '') {
            return $sourceReference;
        }

        return null;
    }

    private function normalizePackageType(?string $rawType, bool $isRoot): string
    {
        if ($isRoot) {
            return PackageTypeInterface::ROOT;
        }

        return match ($rawType) {
            'library' => PackageTypeInterface::LIBRARY,
            'metapackage', 'metadata' => PackageTypeInterface::METAPACKAGE,
            default => PackageTypeInterface::CUSTOM,
        };
    }

    /**
     * Standard priority:
     * 1. config.vendor-bin
     * 2. config.vendor-dir
     * 3. vendor
     *
     * @param array<string, mixed> $rootConfig
     */
    private function resolveVendorDirectory(array $rootConfig): string
    {
        $config = is_array($rootConfig['config'] ?? null) ? $rootConfig['config'] : [];
        $vendorDirectory = $config['vendor-bin'] ?? $config['vendor-dir'] ?? 'vendor';

        if (!is_string($vendorDirectory) || trim($vendorDirectory) === '') {
            return 'vendor';
        }

        return $this->paths->normalizeRelativePath($vendorDirectory);
    }

    /**
     * Resolve package installation path only from composer.json and composer.lock.
     *
     * The default convention is:
     * {vendor-directory}/{package-name}
     *
     * @param array<string, mixed> $packageConfig
     */
    private function resolvePackageRelativePath(string $vendorDirectory, array $packageConfig): string
    {
        $name = (string)($packageConfig['name'] ?? '');

        if ($name === '') {
            throw new RuntimeException('A package in composer.lock does not contain a valid "name".');
        }

        return $this->paths->joinRelativePath($vendorDirectory, $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $file): array
    {
        $json = @file_get_contents($file);

        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read JSON file "%s".', $file));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Invalid JSON in "%s": %s', $file, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('JSON file "%s" does not contain an object.', $file));
        }

        return $data;
    }

    /**
     * @param mixed $packages
     * @return list<array<string, mixed>>
     */
    private function normalizeLockPackages(mixed $packages): array
    {
        if (!is_array($packages)) {
            return [];
        }

        $result = [];

        foreach ($packages as $package) {
            if (is_array($package)) {
                $result[] = $package;
            }
        }

        return $result;
    }

    /**
     * @return PackageRegistryInterface
     * @throws JsonException
     */
    public function loadCurrentRuntimeProject(): PackageRegistryInterface
    {
        return $this->load($this->paths->getCurrentProjectRoot());
    }
}
