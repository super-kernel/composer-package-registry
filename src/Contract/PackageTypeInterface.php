<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Contract;

interface PackageTypeInterface
{
    public const string ROOT = 'root';
    public const string LIBRARY = 'library';
    public const string METAPACKAGE = 'metapackage';
    public const string CUSTOM = 'custom';
}
