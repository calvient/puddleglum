<?php

namespace Calvient\Puddleglum\Generators;

use Calvient\Puddleglum\Definitions\TypeScriptProperty;
use Calvient\Puddleglum\Definitions\TypeScriptType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class ModelGenerator extends AbstractGenerator
{
    protected Model $model;

    protected Collection $columns;

    protected ?Collection $relationInfos = null;

    public function __construct()
    {

    }

    public function getDefinition(): ?string
    {
        return collect([
            $this->getProperties(),
            $this->getRelationProperties(),
            $this->getManyRelationProperties(),
            $this->getAccessors(),
        ])
            ->filter(fn (string $part) => ! empty($part))
            ->join(PHP_EOL);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \ReflectionException
     */
    protected function boot(): void
    {
        $this->model = $this->reflection->newInstance();

        $this->columns = collect(
            Schema::getColumns($this->model->getTable())
        );
    }

    protected function getProperties(): string
    {
        return $this->columns
            ->map(function ($column) {

                return (string) new TypeScriptProperty(
                    name: $column['name'],
                    types: $this->getPropertyType($column['type_name']),
                    nullable: $column['nullable'],
                );
            })
            ->join(PHP_EOL);
    }

    protected function getAccessors(): string
    {
        return collect($this->reflection->getMethods())
            ->reject(fn (ReflectionMethod $method) => $method->isStatic() || $method->getNumberOfParameters())
            ->filter(function (ReflectionMethod $method) {
                return $this->isAccessorMethod($method);
            })
            ->mapWithKeys(function (ReflectionMethod $method) {
                return [$this->accessorPropertyName($method) => $method];
            })
            ->reject(function (ReflectionMethod $method, string $property) {
                return $this->columns->contains(
                    fn ($column) => $column['name'] == $property,
                );
            })
            ->map(function (ReflectionMethod $method, string $property) {
                return (string) new TypeScriptProperty(
                    name: $property,
                    types: TypeScriptType::fromMethod($method),
                    optional: true,
                    readonly: true
                );
            })
            ->join(PHP_EOL);
    }

    protected function getRelationProperties(): string
    {
        return $this->relationInfos()
            ->map(function (array $relation) {
                return (string) new TypeScriptProperty(
                    name: $relation['name'],
                    types: $this->getRelationType($relation),
                    optional: true,
                    nullable: true,
                );
            })
            ->join(PHP_EOL);
    }

    protected function getManyRelationProperties(): string
    {
        return $this->relationInfos()
            ->filter(fn (array $relation) => $relation['many'])
            ->map(function (array $relation) {
                return (string) new TypeScriptProperty(
                    name: $relation['name'].'_count',
                    types: TypeScriptType::NUMBER,
                    optional: true,
                    nullable: true,
                );
            })
            ->join(PHP_EOL);
    }

    protected function relationInfos(): Collection
    {
        if ($this->relationInfos !== null) {
            return $this->relationInfos;
        }

        $this->relationInfos = $this->getMethods()
            ->reject(fn (ReflectionMethod $method) => $this->isAccessorMethod($method))
            // [TODO] Resolve trait/parent relations as well (e.g. DatabaseNotification)
            // skip traits for awhile
            ->reject(fn (ReflectionMethod $method) => $this->methodComesFromTrait($method))
            ->map(function (ReflectionMethod $method) {
                try {
                    $relation = $method->invoke($this->model);
                } catch (Throwable) {
                    return null;
                }

                if (! $relation instanceof Relation) {
                    return null;
                }

                $relationClass = get_class($relation);

                return [
                    'method' => $method,
                    'name' => Str::snake($method->getName()),
                    'related' => $this->getRelatedType($relation),
                    'many' => in_array($relationClass, $this->manyRelationClasses(), true),
                    'one' => in_array($relationClass, $this->oneRelationClasses(), true),
                    'pivot' => in_array($relationClass, [BelongsToMany::class, MorphToMany::class], true),
                ];
            })
            ->filter()
            ->values();

        return $this->relationInfos;
    }

    protected function getMethods(): Collection
    {
        return collect($this->reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn (ReflectionMethod $method) => $method->isStatic())
            ->reject(fn (ReflectionMethod $method) => $method->getNumberOfParameters() > 0);
    }

    protected function getPropertyType(string $type): string|array
    {
        $tsType = match ($type) {
            'tinyint', 'boolean' => TypeScriptType::BOOLEAN,
            'longtext', 'text', 'varchar', 'timestamp', 'datetime', 'date' => TypeScriptType::STRING,
            'int', 'integer', 'bigint', 'float', 'double', 'decimal', 'numeric' => TypeScriptType::NUMBER,
            'json' => [TypeScriptType::array(), TypeScriptType::ANY],
            default => TypeScriptType::ANY
        };

        return $tsType;
    }

    protected function getRelationType(array $relation): string
    {
        $related = $relation['related'];

        if ($relation['many']) {
            if ($relation['pivot']) {
                $related .= ' & { pivot: { [key: string]: any } }';
            }

            return TypeScriptType::array($related);
        }

        if ($relation['one']) {
            return $related;
        }

        return TypeScriptType::ANY;
    }

    private function isAccessorMethod(ReflectionMethod $method): bool
    {
        $name = $method->getName();
        $returnType = $method->getReturnType();

        return (Str::startsWith($name, 'get') && Str::endsWith($name, 'Attribute')) ||
            ($returnType instanceof ReflectionNamedType && $returnType->getName() === Attribute::class);
    }

    private function accessorPropertyName(ReflectionMethod $method): string
    {
        $name = $method->getName();

        if (Str::startsWith($name, 'get') && Str::endsWith($name, 'Attribute')) {
            return (string) Str::of($name)->between('get', 'Attribute')->snake();
        }

        return Str::snake($name);
    }

    private function methodComesFromTrait(ReflectionMethod $method): bool
    {
        return collect($this->reflection->getTraits())
            ->filter(fn (ReflectionClass $trait) => $trait->hasMethod($method->name))
            ->isNotEmpty();
    }

    private function getRelatedType(Relation $relation): string
    {
        $related = str_replace('\\', '.', get_class($relation->getRelated()));

        return str_replace(
            'App.',
            config('puddleglum.namespace', 'Puddleglum').'.',
            $related,
        );
    }

    private function manyRelationClasses(): array
    {
        return [
            HasMany::class,
            BelongsToMany::class,
            HasManyThrough::class,
            MorphMany::class,
            MorphToMany::class,
        ];
    }

    private function oneRelationClasses(): array
    {
        return [
            HasOne::class,
            BelongsTo::class,
            MorphOne::class,
            HasOneThrough::class,
        ];
    }
}
