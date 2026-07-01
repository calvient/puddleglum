<?php

namespace Calvient\Puddleglum;

use Calvient\Puddleglum\Generators\ApiRouteGenerator;
use Calvient\Puddleglum\Generators\ModelGenerator;
use Calvient\Puddleglum\Generators\RequestGenerator;
use Calvient\Puddleglum\Support\TypeScriptFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class PuddleglumGenerator
{
    protected array $generators = [
        Model::class => ModelGenerator::class,
        FormRequest::class => RequestGenerator::class,
        'App\Http\Controllers\Controller' => ApiRouteGenerator::class,
    ];

    private ?Collection $apiRoutesByController = null;

    public function __construct(public string $output, public bool $autoloadDev = false)
    {
    }

    public function execute()
    {
        $this->createNeededFolders($this->output);

        $files = [];

        $this->phpClasses()
            ->groupBy(fn(ReflectionClass $reflection) => $reflection->getNamespaceName())
            ->sortKeys()
            ->each(function (Collection $reflections, string $namespace) use (&$files) {
                foreach ($this->makeNamespace($namespace, $reflections) as $relativePath => $contents) {
                    $files[$relativePath][] = $contents;
                }
            });

        $files['utils.ts'][] = $this->utilFileContents();

        $this->syncOutputFiles($files);
    }

    /**
     * @return array<string, string>
     */
    protected function makeNamespace(string $namespace, Collection $reflections): array
    {
        $pglNamespace = config('puddleglum.namespace');

        $tsNamespace = Str::of($namespace)
            ->after('App\\')
            ->replace('Http\\', '')
            ->replace('\\', '.');

        $contentsByFile = $reflections
            ->sortBy(fn(ReflectionClass $reflection) => $reflection->getName())
            ->map(fn(ReflectionClass $reflection) => $this->runGenerator($reflection, "$pglNamespace.$tsNamespace"))
            ->filter();

        $files = [];
        foreach ($contentsByFile as $file) {
            if ($file['filename'] && $file['contents']) {
                $files[$file['filename']][$file['namespace']][] = $file;
            }
        }

        $generatedFiles = [];
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

                    foreach ($contents as $file) {
                        $generatedFiles[$filename . $folder . '/' . $file['classFilename']] = $file['contents'];
                    }
                } else {
                    $generatedFiles[$filename] = TypeScriptFormatter::namespace(
                        $namespace,
                        collect($contents)->pluck('contents')->all(),
                    );
                }
            }
        }

        return $generatedFiles;
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

        $generatorInstance = $generator === ApiRouteGenerator::class
            ? new $generator($this->apiRoutesByController()->get($reflection->getName(), collect()))
            : new $generator;

        return [
            'namespace' => $namespace,
            'filename' => $generatorInstance->getFileName(),
            'classFilename' => $reflection->getShortName() . '.ts',
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
                    ->map(function (string $className) {
                        try {
                            return new ReflectionClass($className);
                        } catch (ReflectionException) {
                            return null;
                        }
                    })
                    ->filter()
                    ->reject(fn(ReflectionClass $reflection) => $reflection->isAbstract())
                    ->sortBy(fn(ReflectionClass $reflection) => $reflection->getName())
                    ->values();
            });
    }

    private function apiRoutesByController(): Collection
    {
        if ($this->apiRoutesByController === null) {
            $this->apiRoutesByController = ApiRouteGenerator::apiRoutesByController();
        }

        return $this->apiRoutesByController;
    }

    protected function utilFileContents(): string
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
              `${encodeURIComponent(key)}[]=${encodeURIComponent(arrayItem)}`,
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
  links: Array<{ url: string | null; label: string; active: boolean }>;
  next_page_url: string | null;
  per_page: number;
  prev_page_url: string | null;
  to: number;
  total: number;
};
TS;

        return $contents;
    }

    /**
     * @param  array<string, string[]>  $files
     */
    private function syncOutputFiles(array $files): void
    {
        $expectedPaths = [];

        foreach ($files as $relativePath => $chunks) {
            $contents = TypeScriptFormatter::file(implode(PHP_EOL . PHP_EOL, $chunks));
            $path = $this->output . '/' . $relativePath;

            TypeScriptFormatter::writeFileIfChanged($path, $contents);
            $expectedPaths[$path] = true;
        }

        $this->deleteStaleFiles($expectedPaths);
        $this->deleteEmptyDirectories($this->output);
    }

    /**
     * @param  array<string, bool>  $expectedPaths
     */
    private function deleteStaleFiles(array $expectedPaths): void
    {
        if (! is_dir($this->output)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->output, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && ! isset($expectedPaths[$file->getPathname()])) {
                unlink($file->getPathname());
            }
        }
    }

    private function deleteEmptyDirectories(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteEmptyDirectories($path);
            }
        }

        if ($directory !== $this->output && count(scandir($directory)) === 2) {
            rmdir($directory);
        }
    }

    private function createNeededFolders(string $path): void
    {
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function kebabifyPath(string $path): string
    {
        return Str::of($path)->explode('/')
                ->map(fn(string $part) => Str::of($part)->kebab())
                ->implode('/');
    }
}
