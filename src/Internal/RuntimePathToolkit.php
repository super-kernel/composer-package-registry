<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Internal;

use SuperKernel\RuntimeContext\RuntimeContext;
use Throwable;

final class RuntimePathToolkit
{
    public function getCurrentProjectRoot(): string
    {
        $root = $this->tryRuntimeRootPath();

        if ($root !== null) {
            return $root;
        }

        $cwd = getcwd();

        if ($cwd === false || $cwd === '') {
            return '.';
        }

        return $this->normalizeAbsolutePath($cwd);
    }

    public function joinFromProjectRoot(string $projectRoot, string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $this->normalizeAbsolutePath($path);
        }

        $normalizedProjectRoot = $this->normalizeAbsolutePath($projectRoot);
        $normalizedPath = $this->normalizeRelativePath($path);

        $runtimeRoot = $this->tryRuntimeRootPath();
        if ($runtimeRoot !== null && $runtimeRoot === $normalizedProjectRoot) {
            $scoped = $this->tryRuntimeScopedPath($normalizedPath);

            if ($scoped !== null && method_exists($scoped, 'absolute')) {
                $absolute = $scoped->absolute();

                if ($absolute !== '') {
                    return $this->normalizeAbsolutePath($absolute);
                }
            }
        }

        return $this->joinAbsolutePath($normalizedProjectRoot, $normalizedPath);
    }

    public function relativeTo(string $base, string $path): string
    {
        $base = rtrim($this->normalizeAbsolutePath($base), '/');
        $path = $this->normalizeAbsolutePath($path);

        if ($path === $base) {
            return '.';
        }

        $prefix = $base . '/';

        if (str_starts_with($path, $prefix)) {
            return $this->normalizeRelativePath(substr($path, strlen($prefix)));
        }

        return $this->normalizeRelativePath($path);
    }

    public function joinRelativePath(string $base, string $path): string
    {
        $base = $this->normalizeRelativePath($base);
        $path = $this->normalizeRelativePath($path);

        if ($base === '.') {
            return $path;
        }

        if ($path === '.') {
            return $base;
        }

        return $this->normalizeRelativePath($base . '/' . $path);
    }

    public function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~(?<!:)/{2,}~', '/', $path) ?? $path;

        $prefix = '';
        if (preg_match('~^[A-Za-z]:~', $path, $matches) === 1) {
            $prefix = $matches[0];
            $path = substr($path, strlen($prefix));
        }

        $isAbsolute = str_starts_with($path, '/');
        $segments = $this->collapseSegments(explode('/', $path), true);
        $normalized = implode('/', $segments);

        if ($prefix !== '') {
            return rtrim($prefix . '/' . $normalized, '/');
        }

        return $isAbsolute ? '/' . $normalized : ($normalized === '' ? '/' : $normalized);
    }

    public function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        $path = trim($path);

        if ($path === '' || $path === './') {
            return '.';
        }

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        $segments = $this->collapseSegments(explode('/', trim($path, '/')), false);
        $normalized = implode('/', $segments);

        return $normalized === '' ? '.' : $normalized;
    }

    public function joinAbsolutePath(string $base, string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $this->normalizeAbsolutePath($path);
        }

        return $this->normalizeAbsolutePath(rtrim($base, '/') . '/' . ltrim($path, '/'));
    }

    public function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1;
    }

    private function tryRuntimeRootPath(): ?string
    {
        if (!class_exists(RuntimeContext::class) || !method_exists(RuntimeContext::class, 'getContext')) {
            return null;
        }

        try {
            $context = RuntimeContext::getContext();
        } catch (Throwable) {
            return null;
        }

        if (!is_object($context) || !method_exists($context, 'rootPath')) {
            return null;
        }

        try {
            $rootPath = $context->rootPath();
        } catch (Throwable) {
            return null;
        }

        if ($rootPath === '') {
            return null;
        }

        return $this->normalizeAbsolutePath($rootPath);
    }

    private function tryRuntimeScopedPath(string $relativePath): ?object
    {
        if (!class_exists(RuntimeContext::class) || !method_exists(RuntimeContext::class, 'getContext')) {
            return null;
        }

        try {
            $context = RuntimeContext::getContext();
        } catch (Throwable) {
            return null;
        }

        if (!is_object($context) || !method_exists($context, 'path')) {
            return null;
        }

        try {
            $scoped = $context->path($relativePath);
        } catch (Throwable) {
            return null;
        }

        return $scoped;
    }

    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private function collapseSegments(array $segments, bool $isAbsolute): array
    {
        $result = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($result !== [] && end($result) !== '..') {
                    array_pop($result);
                    continue;
                }

                if (!$isAbsolute) {
                    $result[] = '..';
                }

                continue;
            }

            $result[] = $segment;
        }

        return $result;
    }
}
