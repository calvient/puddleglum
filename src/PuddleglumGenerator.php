<?php

namespace Calvient\Puddleglum;

use Calvient\Puddleglum\Generators\ApiRouteGenerator;
use Calvient\Puddleglum\Generators\ModelGenerator;
use Calvient\Puddleglum\Generators\RequestGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class PuddleglumGenerator
{
    protected array $generators = [
        Model::class => ModelGenerator::class,
        FormRequest::class => RequestGenerator::class,
    ];

    public function __construct(public string $output, public bool $autoloadDev = false)
    {
        if (class_exists('App\Http\Controllers\Controller')) {
            $this->generators['App\Http\Controllers\Controller'] = ApiRouteGenerator::class;
        }
    }

    public function execute()
    {
        $this->deleteDirectory($this->output);
        $this->createNeededFolders($this->output);

        // Generate new files
        $this->phpClasses()
            ->groupBy(fn(ReflectionClass $reflection) => $reflection->getNamespaceName())
            ->each(fn(Collection $reflections, string $namespace) => $this->makeNamespace($namespace, $reflections));

        // Create util file
        $this->createUtilFile();
    }

    protected function makeNamespace(string $namespace, Collection $reflections): void
    {
        $pglNamespace = config('puddleglum.namespace');

        $tsNamespace = Str::of($namespace)
            ->after('App\\')
            ->replace('Http\\', '')
            ->replace('\\', '.');

        $contentsByFile = $reflections
            ->map(fn(ReflectionClass $reflection) => $this->runGenerator($reflection, "$pglNamespace.$tsNamespace"))
            ->filter();

        $files = [];
        foreach ($contentsByFile as $file) {
            if ($file['filename'] && $file['contents']) {
                $files[$file['filename']][$file['namespace']][] = $file['contents'];
            }
        }

        foreach ($files as $filename => $namespaces) {
            foreach ($namespaces as $namespace => $contents) {
                if (Str::endsWith($filename, '/')) {
                    // Each class will have its own file
                    $folder = Str::of($namespace)
                        ->replace('.', '/')
                        ->replace('/Controllers', '')
                        ->replace('/Domains', '')
                        ->replace('Glum', '');

                    $folder = $this->kebabifyPath($folder);

                    foreach ($contents as $content) {
                        $extractedFileName = $this->extractFilenameFromContents($content);
                        $this->createNeededFolders($this->output . '/' . $filename . $folder);
                        file_put_contents($this->output . '/' . $filename . $folder . '/' . $extractedFileName, $content);
                    }
                } else {
                    $contents = implode(PHP_EOL, $contents);
                    $contentToWrite = <<<TS
                    export namespace $namespace {
                        $contents
                    }
                    TS;
                    file_put_contents($this->output . '/' . $filename, $contentToWrite, FILE_APPEND);
                }
            }
        }
    }

    protected function runGenerator(ReflectionClass $reflection, ?string $namespace = null): ?array
    {
        $generator = collect($this->generators)
            ->filter(fn(string $generator, string $baseClass) => $reflection->isSubclassOf($baseClass))
            ->values()
            ->first();

        if (!$generator) {
            return null;
        }

        $generatorInstance = new $generator;

        return [
            'namespace' => $namespace,
            'filename' => $generatorInstance->getFileName(),
            'contents' => $generatorInstance->generate($reflection, $namespace),
        ];
    }

    protected function phpClasses(): Collection
    {
        $composer = json_decode(file_get_contents(realpath('composer.json')));

        return collect($composer->autoload->{'psr-4'})
            ->when($this->autoloadDev, function (Collection $paths) use ($composer) {
                return $paths->merge(collect($composer->{'autoload-dev'}?->{'psr-4'}));
            })
            ->flatMap(function (string $path, string $namespace) {
                return collect(
                    (new Finder)
                        ->in($path)
                        ->name('*.php')
                        ->files(),
                )
                    ->map(function (SplFileInfo $file) use ($path, $namespace) {
                        return $namespace .
                            str_replace(
                                ['/', '.php'],
                                ['\\', ''],
                                Str::after(
                                    $file->getRealPath(),
                                    realpath($path) . DIRECTORY_SEPARATOR,
                                ),
                            );
                    })
                    ->filter(function (string $className) {
                        try {
                            new ReflectionClass($className);

                            return true;
                        } catch (ReflectionException) {
                            return false;
                        }
                    })
                    ->map(fn(string $className) => new ReflectionClass($className))
                    ->reject(fn(ReflectionClass $reflection) => $reflection->isAbstract())
                    ->values();
            });
    }

    protected function createUtilFile(): void
    {
        $contents = <<<'TS'
        /**
        * This file is auto generated using 'php artisan puddleglum:generate'
        *
        * Changes to this file will be lost when the command is run again
        */
        export function transformToQueryString(params: Record<string, any>): string {
          return Object.entries(params)
            .filter(([, value]) => value !== null && value !== undefined)
            .map(([key, value]) => {
              if (Array.isArray(value)) {
                return value
                  .map(
                    (arrayItem) =>
                      `${encodeURIComponent(key)}[]=${encodeURIComponent(arrayItem)}`
                  )
                .join('&');
              }
            return `${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
          })
          .join('&');
        }

        export type PaginatedResponse<T> = {
            current_page: number;
            data: T[];
            from: number;
            last_page: number;
            last_page_url: string | null;
            links: Array<{url: string | null; label: string; active: boolean}>;
            next_page_url: string | null;
            per_page: number;
            prev_page_url: string | null;
            to: number;
            total: number;
        };
        TS;

        file_put_contents($this->output . '/utils.ts', $contents);
    }

    private function extractFilenameFromContents(string $contents): string
    {
        // export class RegFormController { -- should be RegFormController.ts
        $matches = [];
        preg_match('/export default class (.*) {/', $contents, $matches);

        return $matches[1] . '.ts';
    }

    private function createNeededFolders(string $path): void
    {
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function deleteDirectory(string $dir)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }

        }

        return rmdir($dir);
    }

    private function kebabifyPath(string $path): string
    {
        return Str::of($path)->explode('/')
                ->map(fn(string $part) => Str::of($part)->kebab())
                ->implode('/');
    }
}
