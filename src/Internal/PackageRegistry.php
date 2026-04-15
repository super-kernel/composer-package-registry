<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Internal;

use ArrayIterator;
use SuperKernel\ComposerPackageRegistry\Contract\PackageInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageRegistryInterface;
use Traversable;
use function array_any;
use function array_values;
use function count;
use function ksort;
use function strtolower;

final class PackageRegistry implements PackageRegistryInterface
{
    /**
     * @var array<string, PackageInterface>
     */
    private array $packages = [];

    /**
     * @param list<PackageInterface> $packages
     */
    public function __construct(
        private readonly PackageInterface $rootPackage,
        private readonly string           $vendorDirectory,
        private readonly string           $cacheDirectory,
        array                             $packages,
    )
    {
        foreach ($packages as $package) {
            $this->packages[$package->getName()] = $package;
        }

        ksort($this->packages);
    }

    public function getRootPackage(): PackageInterface
    {
        return $this->rootPackage;
    }

    public function getPackages(): array
    {
        return array_values($this->packages);
    }

    public function getPackagesByType(string $type): array
    {
        $normalizedType = strtolower($type);
        $packages = [];

        foreach ($this->packages as $package) {
            if (
                strtolower($package->getType()) === $normalizedType
                || strtolower((string)$package->getRawType()) === $normalizedType
            ) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    public function getPackage(string $name): ?PackageInterface
    {
        return $this->packages[$name] ?? null;
    }

    public function hasPackage(string $name): bool
    {
        return isset($this->packages[$name]);
    }

    public function hasDevPackages(): bool
    {
        return array_any($this->packages, fn($package) => $package->isDev());
    }

    public function getVendorDirectory(): string
    {
        return $this->vendorDirectory;
    }

    public function getCacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    public function count(): int
    {
        return count($this->packages);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getPackages());
    }
}
