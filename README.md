# super-kernel/composer-package-registry

[![PHP Version](https://img.shields.io/badge/php-~8.4.0-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Packagist Version](https://img.shields.io/packagist/v/super-kernel/composer-package-registry?logo=packagist&logoColor=white)](https://packagist.org/packages/super-kernel/composer-package-registry)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)
[![GitHub Repo](https://img.shields.io/badge/github-super--kernel%2Fcomposer--package--registry-181717?logo=github)](https://github.com/super-kernel/composer-package-registry)

A read-only Composer package registry component for **SuperKernel**.

It parses `composer.json` and `composer.lock` from a project root, resolves the root package and installed packages,
scans package autoload metadata, and stores each scanned package as an independent cache file under the configured
vendor directory. Path resolution is integrated with `super-kernel/runtime-context`, which acts as the runtime-root
provider and the root-relative path adapter for project-level path operations.

## Features

- Parses the root package from `composer.json`
- Parses installed and dev-installed packages from `composer.lock`
- Treats the current project package as `root`, regardless of its declared Composer type
- Supports `psr-4`, `psr-0`, `classmap`, `files`, and `exclude-from-classmap`
- Keeps `autoload` and `autoload-dev` strictly separated
- Skips only the missing scan target, never the whole registry load because of a single missing autoload-dev path
- Skips package scanning when the physical package directory does not exist
- Caches each package independently under `{vendor}/.super-kernel/packages/`
- Reloads package objects from serialized cache files instead of returning the in-memory scan result directly

## Requirements

- PHP `~8.4.0`
- `super-kernel/runtime-context:dev-main`

## Installation

```bash
composer require super-kernel/composer-package-registry
```

## Package Types

This component uses interface constants instead of enums.

```php
use SuperKernel\ComposerPackageRegistry\Contract\PackageTypeInterface;

PackageTypeInterface::ROOT;
PackageTypeInterface::LIBRARY;
PackageTypeInterface::METAPACKAGE;
PackageTypeInterface::CUSTOM;
```

## Public Contracts

### `PackageInterface`

- `getName(): string`
- `getType(): string`
- `getRawType(): ?string`
- `getReference(): ?string`
- `isRoot(): bool`
- `isDev(): bool`
- `getComposerConfig(): array`
- `getRelativePath(): string`
- `getAutoload(): array`
- `getAutoloadDev(): array`
- `getFiles(bool $includeDev = true): array`
- `getClassMap(bool $includeDev = true): array`

### `PackageRegistryInterface`

- `getRootPackage(): PackageInterface`
- `getPackages(): array`
- `getPackagesByType(string $type): array`
- `getPackage(string $name): ?PackageInterface`
- `hasPackage(string $name): bool`
- `hasDevPackages(): bool`
- `getVendorDirectory(): string`
- `getCacheDirectory(): string`

### `PackageRegistryLoaderInterface`

- `load(string $projectRoot): PackageRegistryInterface`
- `loadCurrentRuntimeProject(): PackageRegistryInterface`

## Vendor Directory Resolution

The vendor directory is resolved with the following priority:

1. `config.vendor-bin`
2. `config.vendor-dir`
3. `vendor`

## Cache Layout

Cache files are stored under:

```text
{vendor-directory}/.super-kernel/packages/
```

Examples:

```text
vendor/.super-kernel/packages/root-package.cache
vendor/.super-kernel/packages/psr/log.cache
vendor/.super-kernel/packages/symfony/console.cache
```

A package name such as `symfony/console` therefore becomes a nested cache path.

## Cache Refresh Rules

If a cache file already exists, it is refreshed when:

- the cached package reference is `null`, or
- the cached package reference differs from the currently installed reference

After refresh, the component reads the cache file again and returns the deserialized `PackageInterface` instance.

## Scanning Rules

### Missing package directory

If the resolved package directory does not exist, package scanning is skipped for that package only.

The package metadata still exists in the registry, but its scanned class map and files are empty.

### Missing `autoload` or `autoload-dev` scan targets

A missing path inside `psr-4`, `psr-0`, `classmap`, or `files` does **not** invalidate the package.
Only that specific target is skipped.

This is especially important for `autoload-dev`: a missing dev path skips only the dev scan path, not the package scan.

## Usage

```php
<?php

declare(strict_types=1);

use SuperKernel\ComposerPackageRegistry\ComposerPackageRegistryLoader;
use SuperKernel\ComposerPackageRegistry\Contract\PackageTypeInterface;

$loader = new ComposerPackageRegistryLoader();
$registry = $loader->load(__DIR__);

$root = $registry->getRootPackage();

var_dump($root->getName());
var_dump($root->getType() === PackageTypeInterface::ROOT);
var_dump($root->getClassMap(includeDev: true));

$package = $registry->getPackage('psr/log');

if ($package !== null) {
    var_dump($package->isDev());
    var_dump($package->getReference());
    var_dump($package->getRelativePath());
    var_dump($package->getFiles());
    var_dump($package->getClassMap());
}
```

## Testing

The PHPUnit suite uses **self-bootstrap tests**.

It parses the current repository's own `composer.json` and `composer.lock`, loads the current project as the root
package, and verifies cache generation under the configured vendor directory. It does not generate ad-hoc fixture
projects inside the test suite.

Run tests with:

```bash
composer phpunit
```

Current assertions cover:

- root package parsing from the current project
- installed package discovery from the current project's `composer.lock`
- package registry filtering, lookup, counting, and iteration
- separation between `autoload` and `autoload-dev`
- cache file generation and cache payload deserialization under `{vendor}/.super-kernel/packages/`
- loading through the current runtime project entrypoint

## Notes

The class map builder scans PHP source files and extracts symbols directly instead of reproducing Composer's generated
autoloader internals byte-for-byte.

For regular PSR-4, PSR-0, classmap, and files-based package layouts this is stable and predictable enough for
framework-level metadata inspection.

## License

This project is licensed under the MIT License.

See the [LICENSE](./LICENSE) file for full license text.
