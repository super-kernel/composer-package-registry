<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Contract;

use Countable;
use IteratorAggregate;
use Traversable;

interface PackageRegistryInterface extends Countable, IteratorAggregate
{
    public function getRootPackage(): PackageInterface;

    /**
     * @return list<PackageInterface>
     */
    public function getPackages(): array;

    /**
     * @param string $type
     * @return list<PackageInterface>
     */
    public function getPackagesByType(string $type): array;

    public function getPackage(string $name): ?PackageInterface;

    public function hasPackage(string $name): bool;

    public function hasDevPackages(): bool;

    /**
     * Returns the path to the vendor directory relative to the project root directory.
     */
    public function getVendorDirectory(): string;

    /**
     * Returns the cache directory path relative to the project root directory.
     */
    public function getCacheDirectory(): string;

    /**
     * @return Traversable<int, PackageInterface>
     */
    public function getIterator(): Traversable;
}
