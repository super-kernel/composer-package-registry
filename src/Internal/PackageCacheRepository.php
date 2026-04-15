<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Internal;

use RuntimeException;
use SuperKernel\ComposerPackageRegistry\Contract\PackageInterface;
use Throwable;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function unserialize;

final readonly class PackageCacheRepository
{
    public function __construct(
        private string             $projectRoot,
        private string             $cacheDirectory,
        private RuntimePathToolkit $paths = new RuntimePathToolkit(),
    )
    {
    }

    /**
     * @param callable():PackageInterface $factory
     */
    public function loadOrRefresh(
        string   $packageName,
        ?string  $installedReference,
        callable $factory,
    ): PackageInterface
    {
        $cacheFile = $this->getCacheFile($packageName);

        $cachedPackage = $this->read($cacheFile);
        $mustRefresh = true;

        if ($cachedPackage !== null) {
            $cachedReference = $cachedPackage->getReference();
            $mustRefresh = $cachedReference === null || $cachedReference !== $installedReference;
        }

        if ($mustRefresh) {
            $package = $factory();
            $this->write($cacheFile, $package);
        }

        $reloaded = $this->read($cacheFile);

        if ($reloaded === null) {
            throw new RuntimeException(sprintf('Unable to read package cache "%s".', $cacheFile));
        }

        return $reloaded;
    }

    private function getCacheFile(string $packageName): string
    {
        $relative = $this->paths->normalizeRelativePath($this->cacheDirectory . '/' . $packageName . '.cache');

        return $this->paths->joinFromProjectRoot($this->projectRoot, $relative);
    }

    private function write(string $cacheFile, PackageInterface $package): void
    {
        $directory = dirname($cacheFile);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create cache directory "%s".', $directory));
        }

        $serialized = serialize($package);

        if (@file_put_contents($cacheFile, $serialized, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write package cache "%s".', $cacheFile));
        }
    }

    private function read(string $cacheFile): ?PackageInterface
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        $serialized = @file_get_contents($cacheFile);

        if ($serialized === false || $serialized === '') {
            return null;
        }

        try {
            $package = unserialize($serialized, [
                'allowed_classes' => [
                    InstalledPackage::class,
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (!$package instanceof PackageInterface) {
            return null;
        }

        return $package;
    }

}
