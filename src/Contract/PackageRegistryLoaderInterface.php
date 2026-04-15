<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Contract;

interface PackageRegistryLoaderInterface
{
    public function load(string $projectRoot): PackageRegistryInterface;

    public function loadCurrentRuntimeProject(): PackageRegistryInterface;
}
