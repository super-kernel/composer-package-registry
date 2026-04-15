<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Contract;

interface PackageInterface
{
    public function getName(): string;

    /**
     * Returns the normalized package type.
     *
     * @return PackageTypeInterface::*
     */
    public function getType(): string;

    public function getRawType(): ?string;

    public function getReference(): ?string;

    public function isRoot(): bool;

    public function isDev(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getComposerConfig(): array;

    /**
     * Returns the path of the package relative to the project directory.
     * The root package name is always "."
     */
    public function getRelativePath(): string;

    /**
     * @return array<string, mixed>
     */
    public function getAutoload(): array;

    /**
     * @return array<string, mixed>
     */
    public function getAutoloadDev(): array;

    /**
     * Returns a list of files relative to the project root directory.
     *
     * @return list<string>
     */
    public function getFiles(bool $includeDev = true): array;

    /**
     * Returns the class diagram of class => relative-path.
     *
     * @return array<class-string, string>
     */
    public function getClassMap(bool $includeDev = true): array;
}
