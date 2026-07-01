<?php

namespace Calvient\Puddleglum\Support;

use RuntimeException;

class TypeScriptFormatter
{
    public static function file(string $contents): string
    {
        return rtrim($contents) . PHP_EOL;
    }

    public static function indent(string $contents, int $spaces = 2): string
    {
        $prefix = str_repeat(' ', $spaces);

        return collect(explode(PHP_EOL, trim($contents)))
            ->map(fn (string $line) => $line === '' ? '' : $prefix . $line)
            ->join(PHP_EOL);
    }

    /**
     * @param  string[]  $members
     */
    public static function namespace(string $name, array $members): string
    {
        $members = array_values(array_filter($members));

        if ($members === []) {
            return "export namespace {$name} {}";
        }

        return "export namespace {$name} {" . PHP_EOL .
            self::indent(implode(PHP_EOL . PHP_EOL, $members)) . PHP_EOL .
            '}';
    }

    public static function interface(string $name, string $definition): string
    {
        return self::block("export interface {$name}", $definition);
    }

    public static function block(string $header, string $contents): string
    {
        $contents = trim($contents);

        if ($contents === '') {
            return "{$header} {}";
        }

        return "{$header} {" . PHP_EOL .
            self::indent($contents) . PHP_EOL .
            '}';
    }

    /**
     * @param  string[]  $arguments
     */
    public static function call(string $callee, array $arguments, int $maxFirstLineLength = 100): string
    {
        $joinedArguments = implode(', ', $arguments);

        if (strlen($callee . '(' . strtok($joinedArguments, PHP_EOL)) <= $maxFirstLineLength) {
            return "{$callee}({$joinedArguments})";
        }

        return $callee . '(' . PHP_EOL .
            self::indent(implode(',' . PHP_EOL, $arguments) . ',') . PHP_EOL .
            ')';
    }

    public static function writeFileIfChanged(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory [{$directory}].");
        }

        if (is_file($path) && file_get_contents($path) === $contents) {
            return;
        }

        file_put_contents($path, $contents);
    }
}
