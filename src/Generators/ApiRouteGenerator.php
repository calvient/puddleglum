<?php

namespace Calvient\Puddleglum\Generators;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use ReflectionClass;

class ApiRouteGenerator extends AbstractGenerator
{
	public function generate(ReflectionClass $reflection): ?string
	{
		$this->reflection = $reflection;
		$this->boot();

		$definition = $this->getDefinition();

		if (!$definition) {
			return null;
		}

		return <<<TS
		export class {$this->tsClassName()} {
		    $definition
		}
		TS;
	}

	public function getDefinition(): ?string
	{
		$apiRoutes = collect(\App::make('router')->getRoutes())
			->filter(
				fn($route) => collect(
					array_key_exists('middleware', $route->action)
						? $route->action['middleware']
						: [],
				)->contains('api'),
			)
			->filter(
				fn($route) => Str::of(
					array_key_exists('controller', $route->action)
						? $route->action['controller']
						: '',
				)->contains($this->reflection->getName()),
			)
			->map(function ($route) {
				$pathParameters = collect(explode('{', Str::of($route->uri)))
					->filter(fn($part) => Str::contains($part, '}'))
					->map(
						fn($part) => [
							'name' => Str::of($part)
								->before('}')
								->replace(['}', '/', '?'], '')
								->toString(),
							'required' => Str::contains($part, '?') ? false : true,
						],
					)
					->toArray();

				[$controller, $methodName] = explode('@', $route->action['controller']);
				$controller = new ReflectionClass($controller);
				$method = $controller->getMethod($methodName);
				$request = collect($method->getParameters())->first(
					fn($parameter) => $parameter->getClass() &&
						$parameter->getClass()->isSubclassOf(FormRequest::class),
				);
				$glumRequest = collect($method->getAttributes())->first(
					fn($attribute) => $attribute->getName() ===
						'Calvient\Puddleglum\Attributes\GlumRequest',
				);
				$glumResponse = collect($method->getAttributes())->first(
					fn($attribute) => $attribute->getName() ===
						'Calvient\Puddleglum\Attributes\GlumResponse',
				);

				return [
					'controller' => Str::of($controller->getName())
						->after('App\\Http\\Controllers\\')
						->replace('\\', '.')
						->toString(),
					'action' => $methodName,
					'methods' => $route->methods,
					'path' => $route->uri,
					'pathParameters' => $pathParameters,
					'request' => $request
						? Str::of($request->getType()->getName())
							->replace('App\\', 'Puddleglum\\')
							->replace('Http\\', '')
							->replace('\\', '.')
							->toString()
						: null,
					'glumRequest' => $glumRequest?->getArguments()[0] ?? null,
					'glumResponse' => $glumResponse?->getArguments()[0] ?? null,
				];
			});

		return $apiRoutes
			->map(function ($route) {
				$controller = $route['controller'];
				$action = $route['action'];
				$method = Str::of($route['methods'][0])
					->lower()
					->toString();
				$path = Str::of($route['path'])
					->replace(['{', '}'], ['${', '}'])
					->replace('?', '')
					->toString();
				$pathParameters = $route['pathParameters'];
				$request = $route['request'];
				$glumRequest = $route['glumRequest'];
				$glumResponse = $route['glumResponse'];

				return <<<TS
				static async $action({$this->makeApiSignature(
					$pathParameters,
					$request,
					$glumRequest,
				)}) {
				    return {$this->makeAxiosCall(
					$method,
					$path,
					$request,
					$glumRequest,
					$glumResponse,
				)};
				}
				TS;
			})
			->join(PHP_EOL);
	}

	protected function makeApiSignature($pathParameters, $request, $glumRequest): string
	{
		$signature = '';

		if ($pathParameters) {
			$signature .= collect($pathParameters)
				->map(fn($parameter) => $parameter['name'] . ': string|number')
				->join(', ');
		}

		if ($request) {
			$signature .= $signature ? ', ' : '';
			$signature .= 'request: ' . $request;
		} elseif ($glumRequest) {
			$signature .= $signature ? ', ' : '';
			$signature .=
				'request: {' . $this->transformResponseToTypescriptType($glumRequest) . '}';
		}

		return $signature;
	}

	protected function makeAxiosCall($method, $path, $request, $glumRequest, $response): string
	{
		$generic = $response ? $this->transformResponseToTypescriptType($response) : '';
		$path = Str::of($path)->startsWith('/') ? $path : "/$path";
		$call = "axios.$method$generic(`$path`";

		if ($request || $glumRequest) {
			$call .= $method === 'get' ? '?${transformToQueryString(request)}' : ', request';
		}

		$call .= ')';

		return $call;
	}

	protected function transformResponseToTypescriptType(array|string $reponse)
	{
		if (is_array($reponse)) {
			return '<{' .
				collect($reponse)
					->map(
						fn($value, $key) => $key .
							': ' .
							$this->transformPhpTypeToTypescript($value),
					)
					->join(',') .
				'}>';
		} else {
			return '<' . $this->transformPhpTypeToTypescript($reponse) . '>';
		}
	}

	protected function transformPhpTypeToTypescript($value)
	{
		$typescriptPrimitives = [
			'string',
			'number',
			'boolean',
			'any',
			'unknown',
			'void',
			'null',
			'undefined',
		];

		if (in_array($value, $typescriptPrimitives)) {
			return $value;
		}

		return config('puddleglum.models_namespace', 'Puddleglum.Models') . '.' . $value;
	}
}
