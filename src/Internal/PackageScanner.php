<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Internal;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use function file_exists;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function ltrim;
use function pathinfo;
use function preg_match;
use function preg_quote;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class PackageScanner
{
    public function __construct(
        private PhpSymbolExtractor $symbolExtractor = new PhpSymbolExtractor(),
        private RuntimePathToolkit $paths = new RuntimePathToolkit(),
    )
    {
    }

    /**
     * @param array<string, mixed> $autoload
     * @param array<string, mixed> $autoloadDev
     * @return array{
     *     classMap: array<class-string, string>,
     *     devClassMap: array<class-string, string>,
     *     files: list<string>,
     *     devFiles: list<string>
     * }
     */
    public function scan(
        string $projectRoot,
        string $packageRelativePath,
        array  $autoload,
        array  $autoloadDev,
    ): array
    {
        $projectRoot = $this->paths->normalizeAbsolutePath($projectRoot);
        $packageRoot = $packageRelativePath === '.'
            ? $projectRoot
            : $this->paths->joinFromProjectRoot($projectRoot, $packageRelativePath);

        if (!is_dir($packageRoot)) {
            return [
                'classMap' => [],
                'devClassMap' => [],
                'files' => [],
                'devFiles' => [],
            ];
        }

        $classMap = [];
        $devClassMap = [];

        $this->collectAutoload($classMap, $autoload, $packageRoot, $projectRoot);
        $this->collectAutoload($devClassMap, $autoloadDev, $packageRoot, $projectRoot);

        ksort($classMap);
        ksort($devClassMap);

        $files = $this->resolveFiles($packageRelativePath, $packageRoot, $autoload);
        $devFiles = $this->resolveFiles($packageRelativePath, $packageRoot, $autoloadDev);

        return [
            'classMap' => $classMap,
            'devClassMap' => $devClassMap,
            'files' => $files,
            'devFiles' => $devFiles,
        ];
    }

    /**
     * @param array<class-string, string> $classMap
     * @param array<string, mixed> $autoload
     */
    private function collectAutoload(
        array  &$classMap,
        array  $autoload,
        string $packageRoot,
        string $projectRoot,
    ): void
    {
        $excludePatterns = $this->normalizePathList($autoload['exclude-from-classmap'] ?? []);

        $this->collectPsrMappings(
            $classMap,
            is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [],
            $packageRoot,
            $projectRoot,
            $excludePatterns,
        );

        $this->collectPsrMappings(
            $classMap,
            is_array($autoload['psr-0'] ?? null) ? $autoload['psr-0'] : [],
            $packageRoot,
            $projectRoot,
            $excludePatterns,
        );

        foreach ($this->normalizePathList($autoload['classmap'] ?? []) as $path) {
            $absolutePath = $this->paths->joinAbsolutePath($packageRoot, $path);
            $this->collectFromPath($classMap, $absolutePath, $packageRoot, $projectRoot, $excludePatterns, null);
        }
    }

    /**
     * @param array<class-string, string> $classMap
     * @param array<string, mixed> $mapping
     * @param list<string> $excludePatterns
     */
    private function collectPsrMappings(
        array  &$classMap,
        array  $mapping,
        string $packageRoot,
        string $projectRoot,
        array  $excludePatterns,
    ): void
    {
        foreach ($mapping as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }

            foreach ($this->normalizePathList($paths) as $path) {
                $absolutePath = $this->paths->joinAbsolutePath($packageRoot, $path);
                $this->collectFromPath(
                    $classMap,
                    $absolutePath,
                    $packageRoot,
                    $projectRoot,
                    $excludePatterns,
                    trim($prefix, '\\'),
                );
            }
        }
    }

    /**
     * @param array<class-string, string> $classMap
     * @param list<string> $excludePatterns
     */
    private function collectFromPath(
        array   &$classMap,
        string  $path,
        string  $packageRoot,
        string  $projectRoot,
        array   $excludePatterns,
        ?string $namespacePrefix,
    ): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            $this->indexFile($classMap, $path, $packageRoot, $projectRoot, $excludePatterns, $namespacePrefix);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $file = $fileInfo->getPathname();
            $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));

            if (!in_array($extension, ['php', 'inc'], true)) {
                continue;
            }

            $this->indexFile($classMap, $file, $packageRoot, $projectRoot, $excludePatterns, $namespacePrefix);
        }
    }

    /**
     * @param array<class-string, string> $classMap
     * @param list<string> $excludePatterns
     */
    private function indexFile(
        array   &$classMap,
        string  $file,
        string  $packageRoot,
        string  $projectRoot,
        array   $excludePatterns,
        ?string $namespacePrefix,
    ): void
    {
        $packageRelativePath = $this->paths->relativeTo($packageRoot, $file);

        if ($this->isExcluded($packageRelativePath, $excludePatterns)) {
            return;
        }

        $classes = $this->symbolExtractor->extractClasses($file);

        if ($classes === []) {
            return;
        }

        $projectRelativePath = $this->paths->relativeTo($projectRoot, $file);

        foreach ($classes as $class) {
            if ($namespacePrefix !== null && $namespacePrefix !== '') {
                if ($class !== $namespacePrefix && !str_starts_with($class, $namespacePrefix . '\\')) {
                    continue;
                }
            }

            $classMap[$class] = $projectRelativePath;
        }
    }

    /**
     * @param array<string, mixed> $autoload
     * @return list<string>
     */
    private function resolveFiles(string $packageRelativePath, string $packageRoot, array $autoload): array
    {
        $resolved = [];

        foreach ($this->normalizePathList($autoload['files'] ?? []) as $file) {
            $absolute = $this->paths->joinAbsolutePath($packageRoot, $file);

            if (!is_file($absolute)) {
                continue;
            }

            $resolved[] = $this->paths->joinRelativePath($packageRelativePath, $file);
        }

        $resolved = array_values(array_unique($resolved));
        sort($resolved);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function normalizePathList(mixed $value): array
    {
        if (is_string($value)) {
            return [$this->paths->normalizeRelativePath($value)];
        }

        if (!is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $paths[] = $this->paths->normalizeRelativePath($item);
            }
        }

        return $paths;
    }

    /**
     * exclude-from-classmap simplified matcher:
     * - supports relative paths
     * - supports *
     * - supports **
     * - treats a directory path as all nested content
     */
    private function isExcluded(string $packageRelativePath, array $patterns): bool
    {
        $path = ltrim(str_replace('\\', '/', $packageRelativePath), '/');

        foreach ($patterns as $pattern) {
            $pattern = ltrim(str_replace('\\', '/', $pattern), '/');

            if ($pattern === '') {
                continue;
            }

            if (!str_contains($pattern, '*') && !str_ends_with($pattern, '/')) {
                if ($path === $pattern) {
                    return true;
                }

                continue;
            }

            if (str_ends_with($pattern, '/')) {
                $pattern .= '**';
            }

            $regex = preg_quote($pattern, '~');
            $regex = str_replace('\\*\\*', '.*', $regex);
            $regex = str_replace('\\*', '[^/]*', $regex);
            $regex = '~^' . $regex . '$~';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

}
