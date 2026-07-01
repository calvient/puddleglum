<?php

namespace Calvient\Puddleglum\Generators;

use App;
use Calvient\Puddleglum\Support\TypeScriptFormatter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;

class ApiRouteGenerator extends AbstractGenerator
{
    // Since this references a folder, each class will have its own file
    protected string $filename = 'api/';

    protected string $fileImports = <<<'TS'
/* eslint-disable @typescript-eslint/no-unused-vars */
import axios, { AxiosRequestConfig } from 'axios';
import { transformToQueryString, PaginatedResponse } from 'puddleglum/utils';
import { Glum } from 'puddleglum';
TS;

    public function __construct(private ?Collection $routes = null)
    {
    }

    public static function apiRoutesByController(): Collection
    {
        return collect(App::make('router')->getRoutes())
            ->filter(fn($route) => collect($route->action['middleware'] ?? [])->contains('api'))
            ->map(fn($route) => self::routeMetadata($route))
            ->filter()
            ->groupBy('controller');
    }

    public function generate(ReflectionClass $reflection, ?string $namespace = null): ?string
    {
        $this->reflection = $reflection;
        $this->boot();

        $definition = $this->getDefinition();

        if (!$definition) {
            return null;
        }

        return $this->fileImports . PHP_EOL . PHP_EOL .
            TypeScriptFormatter::block("export default class {$this->tsClassName()}", $definition);
    }

    public function getDefinition(): ?string
    {
        $apiRoutes = $this->routes ?? static::apiRoutesByController()
            ->get($this->reflection->getName(), collect());

        return $apiRoutes
            ->map(function ($route) {
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

                $signature = $this->makeApiSignature($pathParameters, $request, $glumRequest);
                $axiosCall = $this->makeAxiosCall($method, $path, $request, $glumRequest, $glumResponse);

                $methodHeader = "static async {$action}(" . PHP_EOL .
                    TypeScriptFormatter::indent($signature) . PHP_EOL .
                    ')';

                return TypeScriptFormatter::block($methodHeader, "return {$axiosCall};");
            })
            ->join(PHP_EOL . PHP_EOL);
    }

    protected function makeApiSignature($pathParameters, $request, $glumRequest): string
    {
        $parameters = [];

        if ($pathParameters) {
            $parameters = collect($pathParameters)
                ->map(fn($parameter) => $parameter['name'] . ': string | number')
                ->all();
        }

        if ($request) {
            $parameters[] = "request: {$request} = {} as {$request}";
        } elseif ($glumRequest !== null) {
            $request = $this->transformResponseToTypescriptType($glumRequest);
            $isOptional = $this->isEveryMemberOptional($request);
            $parameters[] = $isOptional ? "request: {$request} = {}" : "request: {$request}";
        }

        $parameters[] = 'validationOnly: boolean = false';
        $parameters[] = "fieldToValidate: string = ''";
        $parameters[] = 'config: AxiosRequestConfig = {}';

        return collect($parameters)
            ->map(fn(string $parameter) => $parameter . ',')
            ->join(PHP_EOL);
    }

    protected function makeAxiosCall($method, $path, $request, $glumRequest, $response): string
    {
        $generic = $response ? $this->transformResponseToTypescriptType($response, true) : '';
        $path = Str::of($path)->startsWith('/') ? $path : "/$path";
        $pathArgument = "`{$path}";

        if ($request || $glumRequest !== null) {
            $pathArgument .= $method === 'get' ? '?${transformToQueryString(request)}`' : '`';
        } else {
            $pathArgument .= '`';
        }

        $arguments = [$pathArgument];

        if ($request || $glumRequest !== null) {
            if ($method !== 'get') {
                $arguments[] = 'request';
            }
        } elseif (! in_array($method, ['get', 'delete'], true)) {
            $arguments[] = '{}';
        }

        $arguments[] = $this->makeAxiosConfig();

        return TypeScriptFormatter::call("axios.{$method}{$generic}", $arguments);
    }

    protected function transformResponseToTypescriptType(
        array|string $response,
        bool         $asGeneric = false,
    )
    {
        $prefix = $asGeneric ? '<' : '';
        $suffix = $asGeneric ? '>' : '';

        if (is_array($response)) {
            return $prefix .
                '{' . PHP_EOL .
                collect($response)
                    ->map(
                        fn($value, $key) => $key .
                            ': ' .
                            $this->transformPhpTypeToTypescript($value) .
                            ';',
                    )
                    ->pipe(fn($members) => TypeScriptFormatter::indent($members->join(PHP_EOL))) .
                PHP_EOL .
                '}' .
                $suffix;
        } else {
            return $prefix . $this->transformPhpTypeToTypescript($response) . $suffix;
        }
    }

    protected function transformPhpTypeToTypescript($value)
    {
        if (preg_match('/^PaginatedResponse<([A-Za-z_][A-Za-z0-9_]*)>$/', $value, $matches)) {
            return 'PaginatedResponse<' . $this->transformPhpTypeToTypescript($matches[1]) . '>';
        }

        $typescriptPrimitives = [
            'string',
            'number',
            'boolean',
            'any',
            'unknown',
            'void',
            'null',
            'undefined',
            'Array<',
            'Partial<',
            'Pick<',
            'Omit<',
            'Record<',
            'Readonly<',
            'Exclude<',
            'PaginatedResponse<',
        ];

        if (Str::of($value)->startsWith($typescriptPrimitives) || Str::of($value)->contains('.')) {
            return $value;
        }

        return config('puddleglum.namespace', 'Puddleglum') .
            '.' .
            config('puddleglum.models_namespace', 'Models') .
            '.' .
            $value;
    }

    private function isEveryMemberOptional(string $type): bool
    {
        return Str::of($type)->split('/[;,]/')
            ->filter(fn($line) => Str::of($line)->contains(':'))
            ->every(function ($member) {
                return Str::of($member)->contains('?:');
            });
    }

    private function makeAxiosConfig(): string
    {
        return <<<'TS'
{
  headers: {
    Precognition: validationOnly,
    ...(fieldToValidate
      ? { 'Precognition-Validate-Only': fieldToValidate }
      : {}),
  },
  ...config,
}
TS;
    }

    private static function routeMetadata($route): ?array
    {
        $controllerAction = $route->action['controller'] ?? null;
        if (! is_string($controllerAction) || $controllerAction === '') {
            return null;
        }

        [$controller, $methodName] = self::controllerAndMethod($controllerAction);
        $controller = ltrim($controller, '\\');

        if (! class_exists($controller) || ! method_exists($controller, $methodName)) {
            return null;
        }

        $controllerReflection = new ReflectionClass($controller);
        $method = $controllerReflection->getMethod($methodName);
        $request = collect($method->getParameters())->first(
            fn($parameter) => $parameter->getClass() &&
                $parameter->getClass()->isSubclassOf(FormRequest::class),
        );
        $requestType = $request?->getType();
        $glumRequest = collect($method->getAttributes())->first(
            fn($attribute) => $attribute->getName() ===
                'Calvient\Puddleglum\Attributes\GlumRequest',
        );
        $glumResponse = collect($method->getAttributes())->first(
            fn($attribute) => $attribute->getName() ===
                'Calvient\Puddleglum\Attributes\GlumResponse',
        );

        return [
            'controller' => $controller,
            'action' => $methodName === '__invoke' ? 'invoke' : $methodName,
            'methods' => $route->methods,
            'path' => $route->uri,
            'pathParameters' => self::pathParameters($route->uri),
            'request' => $requestType instanceof ReflectionNamedType
                ? Str::of($requestType->getName())
                    ->replace('App\\', config('puddleglum.namespace', 'Puddleglum') . '\\')
                    ->replace('Http\\', '')
                    ->replace('\\', '.')
                    ->toString()
                : null,
            'glumRequest' => $glumRequest?->getArguments()[0] ?? null,
            'glumResponse' => $glumResponse?->getArguments()[0] ?? null,
        ];
    }

    private static function controllerAndMethod(string $controllerAction): array
    {
        if (Str::contains($controllerAction, '@')) {
            return explode('@', $controllerAction, 2);
        }

        return [$controllerAction, '__invoke'];
    }

    private static function pathParameters(string $uri): array
    {
        return collect(explode('{', $uri))
            ->filter(fn($part) => Str::contains($part, '}'))
            ->map(
                fn($part) => [
                    'name' => Str::of($part)
                        ->before('}')
                        ->replace(['}', '/', '?'], '')
                        ->toString(),
                    'required' => ! Str::contains($part, '?'),
                ],
            )
            ->toArray();
    }
}
