<?php

declare(strict_types=1);

namespace SuperKernel\ComposerPackageRegistry\Internal;

use function array_values;
use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function token_get_all;
use function trim;
use const T_ABSTRACT;
use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_ENUM;
use const T_FINAL;
use const T_INTERFACE;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_READONLY;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;
use const TOKEN_PARSE;

final class PhpSymbolExtractor
{
    /**
     * @return list<class-string>
     */
    public function extractClasses(string $file): array
    {
        $code = @file_get_contents($file);

        if ($code === false || $code === '') {
            return [];
        }

        $tokens = token_get_all($code, TOKEN_PARSE);
        $namespace = '';
        $symbols = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (!is_array($token)) {
                continue;
            }

            $id = $token[0];

            if ($id === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $index + 1);
                continue;
            }

            if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            if ($id === T_CLASS) {
                $previous = $this->previousMeaningfulTokenId($tokens, $index);
                if ($previous === T_NEW || $previous === T_DOUBLE_COLON) {
                    continue;
                }
            }

            $name = $this->readFollowingIdentifier($tokens, $index + 1);

            if ($name === null) {
                continue;
            }

            $fqcn = ltrim($namespace . '\\' . $name, '\\');
            $symbols[$fqcn] = $fqcn;
        }

        return array_values($symbols);
    }

    private function readNamespace(array $tokens, int $offset): string
    {
        $parts = [];
        $count = count($tokens);

        for ($index = $offset; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === ';' || $token === '{') {
                    break;
                }

                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $parts[] = $token[1];
            }
        }

        return trim(implode('', $parts), '\\');
    }

    private function readFollowingIdentifier(array $tokens, int $offset): ?string
    {
        $count = count($tokens);

        for ($index = $offset; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === '{' || $token === '(' || $token === ';') {
                    return null;
                }

                continue;
            }

            if ($token[0] === T_STRING) {
                return $token[1];
            }

            if (!in_array($token[0], [T_WHITESPACE, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                return null;
            }
        }

        return null;
    }

    private function previousMeaningfulTokenId(array $tokens, int $offset): int|string|null
    {
        for ($index = $offset - 1; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if (trim($token) === '') {
                    continue;
                }

                return $token;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return null;
    }
}
