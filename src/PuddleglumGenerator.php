<?php

namespace Calvient\Puddleglum;

use App\Http\Controllers\Controller;
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
		Controller::class => ApiRouteGenerator::class,
	];

	public function __construct(public string $output, public bool $autoloadDev = false)
	{
	}

	public function execute()
	{
		$types = $this->phpClasses()
			->groupBy(fn(ReflectionClass $reflection) => $reflection->getNamespaceName())
			->map(
				fn(Collection $reflections, string $namespace) => $this->makeNamespace(
					$namespace,
					$reflections,
				),
			)
			->reject(fn(string $namespaceDefinition) => empty($namespaceDefinition))
			->prepend(
				<<<TS
				/**
				* This file is auto generated using 'php artisan puddleglum:generate'
				*
				* Changes to this file will be lost when the command is run again
				*/
				// eslint-disable-next-line max-classes-per-file
				import axios from 'axios';

				function transformToQueryString(params: Record<string, any>): string {
				    return Object.keys(params)
				        .map((key) => `\${encodeURIComponent(key)}=\${encodeURIComponent(params[key], )}`)
				        .join('&');
				}
				TS
				,
			)
			->join(PHP_EOL);

		file_put_contents($this->output, $types);
	}

	protected function makeNamespace(string $namespace, Collection $reflections): string
	{
		$generatedCode = $reflections
			->map(fn(ReflectionClass $reflection) => $this->runGenerator($reflection))
			->whereNotNull()
			->join(PHP_EOL);

		$tsNamespace = Str::of($namespace)
			->after('App\\')
			->replace('Http\\', '')
			->replace('\\', '.');

		if (!$generatedCode) {
			return '';
		}

		return <<<TS
		export namespace Puddleglum.$tsNamespace {
		    $generatedCode
		}
		TS;
	}

	protected function runGenerator(ReflectionClass $reflection): ?string
	{
		$generator = collect($this->generators)
			->filter(
				fn(string $generator, string $baseClass) => $reflection->isSubclassOf($baseClass),
			)
			->values()
			->first();

		if (!$generator) {
			return null;
		}

		return (new $generator())->generate($reflection);
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
					(new Finder())
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
}
