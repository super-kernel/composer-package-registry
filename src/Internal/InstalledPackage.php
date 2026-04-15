<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Internal;

use SuperKernel\ComposerPackageRegistry\Contract\PackageInterface;
use SuperKernel\ComposerPackageRegistry\Contract\PackageTypeInterface;
use function array_merge;
use function array_unique;
use function array_values;
use function ksort;
use function sort;

final readonly class InstalledPackage implements PackageInterface
{
    /**
     * @param string $name
     * @param string $type
     * @param string|null $rawType
     * @param string|null $reference
     * @param bool $dev
     * @param array<string, mixed> $composerConfig
     * @param string $relativePath
     * @param array<string, mixed> $autoload
     * @param array<string, mixed> $autoloadDev
     * @param array<class-string, string> $classMap
     * @param array<class-string, string> $devClassMap
     * @param list<string> $files
     * @param list<string> $devFiles
     */
    public function __construct(
        private string  $name,
        private string  $type,
        private ?string $rawType,
        private ?string $reference,
        private bool    $dev,
        private array   $composerConfig,
        private string  $relativePath,
        private array   $autoload,
        private array   $autoloadDev,
        private array   $classMap,
        private array   $devClassMap,
        private array   $files,
        private array   $devFiles,
    )
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRawType(): ?string
    {
        return $this->rawType;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function isRoot(): bool
    {
        return $this->type === PackageTypeInterface::ROOT;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }

    public function getComposerConfig(): array
    {
        return $this->composerConfig;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function getAutoload(): array
    {
        return $this->autoload;
    }

    public function getAutoloadDev(): array
    {
        return $this->autoloadDev;
    }

    public function getFiles(bool $includeDev = true): array
    {
        if (!$includeDev) {
            return $this->files;
        }

        $files = array_values(array_unique(array_merge($this->files, $this->devFiles)));
        sort($files);

        return $files;
    }

    public function getClassMap(bool $includeDev = true): array
    {
        if (!$includeDev) {
            return $this->classMap;
        }

        $classMap = $this->classMap + $this->devClassMap;
        ksort($classMap);

        return $classMap;
    }
}
