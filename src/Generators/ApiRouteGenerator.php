<?php

namespace Calvient\Puddleglum\Generators;

use App;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use ReflectionClass;

class ApiRouteGenerator extends AbstractGenerator
{
    // Since this references a folder, each class will have its own file
    protected string $filename = 'api/';

    protected string $fileImports = "/* eslint-disable @typescript-eslint/no-unused-vars */\n" .
    "import axios, {AxiosRequestConfig} from 'axios';\n" .
    "import {transformToQueryString, PaginatedResponse} from 'puddleglum/utils';\n" .
    "import {Glum} from 'puddleglum';\n\n";

    public function generate(ReflectionClass $reflection, ?string $namespace = null): ?string
    {
        $this->reflection = $reflection;
        $this->boot();

        $definition = $this->getDefinition();

        if (!$definition) {
            return null;
        }

        return <<<TS
		{$this->fileImports}
		export default class {$this->tsClassName()} {
		    $definition
		}
		TS;
    }

    public function getDefinition(): ?string
    {
        $apiRoutes = collect(App::make('router')->getRoutes())
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

                $controller = $route->action['controller'];
                $methodName = '__invoke';

                // Check if the controller is invokable
                if (Str::contains($controller, '@')) {
                    [$controller, $methodName] = explode('@', $controller);
                }

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
                    'action' => $methodName === '__invoke' ? 'invoke' : $methodName,
                    'methods' => $route->methods,
                    'path' => $route->uri,
                    'pathParameters' => $pathParameters,
                    'request' => $request
                        ? Str::of($request->getType()->getName())
                            ->replace('App\\', config('puddleglum.namespace', 'Puddleglum') . '\\')
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
				static async $action({$this->makeApiSignature($pathParameters, $request, $glumRequest)}) {
				    return {$this->makeAxiosCall($method, $path, $request, $glumRequest, $glumResponse)};
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
            $signature .= "request: $request = {} as $request";
        } elseif ($glumRequest) {
            $request = $this->transformResponseToTypescriptType($glumRequest);
            $isOptional = $this->isEveryMemberOptional($request);
            $signature .= $signature ? ', ' : '';
            $signature .= $isOptional ? "request: $request = {}" : "request: $request";
        }

        // Add precognitive support
        $signature .= $signature ? ', ' : '';
        $signature .=
            'validationOnly: boolean = false, fieldToValidate: string = "", config: AxiosRequestConfig = {}';

        return $signature;
    }

    protected function makeAxiosCall($method, $path, $request, $glumRequest, $response): string
    {
        $generic = $response ? $this->transformResponseToTypescriptType($response, true) : '';
        $path = Str::of($path)->startsWith('/') ? $path : "/$path";
        $call = "axios.$method$generic(`$path";

        if ($request || $glumRequest) {
            $call .= $method === 'get' ? '?${transformToQueryString(request)}`' : '`, request';
        } else {
            $call .= '`';
        }

        // Add precognitive support
        $call .=
            ', { headers: { "Precognition": validationOnly, ...fieldToValidate ? {"Precognition-Validate-Only": fieldToValidate} : {} }, ...config }';

        $call .= ')';

        return $call;
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
                '{' .
                collect($response)
                    ->map(
                        fn($value, $key) => $key .
                            ': ' .
                            $this->transformPhpTypeToTypescript($value),
                    )
                    ->join(',') .
                '}' .
                $suffix;
        } else {
            return $prefix . $this->transformPhpTypeToTypescript($response) . $suffix;
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
            'Array<',
            'Partial<',
            'Pick<',
            'Omit<',
            'Record<',
            'Readonly<',
            'Exclude<',
            'PaginatedResponse<',
        ];

        if (Str::of($value)->startsWith($typescriptPrimitives)) {
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
}
