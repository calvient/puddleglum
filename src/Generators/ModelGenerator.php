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
use Throwable;

class ModelGenerator extends AbstractGenerator
{
    protected Model $model;

    protected Collection $columns;

    public function __construct()
    {

    }

    public function getDefinition(): ?string
    {
        return collect([
            $this->getProperties(),
            $this->getRelations(),
            $this->getManyRelations(),
            $this->getAccessors(),
        ])
            ->filter(fn (string $part) => ! empty($part))
            ->join(PHP_EOL.'        ');
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
            ->join(PHP_EOL.'        ');
    }

    protected function getAccessors(): string
    {
        return collect($this->reflection->getMethods())
            ->reject(fn (ReflectionMethod $method) => $method->isStatic() || $method->getNumberOfParameters())
            ->filter(function (ReflectionMethod $method) {
                $name = $method->getName();
                $returnType = $method->getReturnType();

                $isOldStyleAccessor = Str::startsWith($name, 'get') && Str::endsWith($name, 'Attribute');
                $isNewStyleAccessor = $returnType && $returnType->getName() === Attribute::class;

                return $isOldStyleAccessor || $isNewStyleAccessor;
            })
            ->mapWithKeys(function (ReflectionMethod $method) {
                $name = $method->getName();
                $returnType = $method->getReturnType();

                if (Str::startsWith($name, 'get') && Str::endsWith($name, 'Attribute')) {
                    $property = (string) Str::of($name)->between('get', 'Attribute')->snake();
                } elseif ($returnType && $returnType->getName() === Attribute::class) {
                    $property = Str::snake($name);
                } else {
                    return [];
                }
                return [$property => $method];
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
            ->join(PHP_EOL.'        ');
    }

    protected function getRelations(): string
    {
        return $this->getRelationMethods()
            ->map(function (ReflectionMethod $method) {
                return (string) new TypeScriptProperty(
                    name: Str::snake($method->getName()),
                    types: $this->getRelationType($method),
                    optional: true,
                    nullable: true,
                );
            })
            ->join(PHP_EOL.'        ');
    }

    protected function getManyRelations(): string
    {
        return $this->getRelationMethods()
            ->filter(fn (ReflectionMethod $method) => $this->isManyRelation($method))
            ->map(function (ReflectionMethod $method) {
                return (string) new TypeScriptProperty(
                    name: Str::snake($method->getName()).'_count',
                    types: TypeScriptType::NUMBER,
                    optional: true,
                    nullable: true,
                );
            })
            ->join(PHP_EOL.'        ');
    }

    protected function getRelationMethods(): Collection
    {
        return $this->getMethods()
            ->filter(function (ReflectionMethod $method) {
                try {
                    return $method->invoke($this->model) instanceof Relation;
                } catch (Throwable) {
                    return false;
                }
            })
            // [TODO] Resolve trait/parent relations as well (e.g. DatabaseNotification)
            // skip traits for awhile
            ->filter(function (ReflectionMethod $method) {
                return collect($this->reflection->getTraits())
                    ->filter(function (ReflectionClass $trait) use ($method) {
                        return $trait->hasMethod($method->name);
                    })
                    ->isEmpty();
            });
    }

    protected function getMethods(): Collection
    {
        return collect($this->reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn (ReflectionMethod $method) => $method->isStatic())
            ->reject(fn (ReflectionMethod $method) => $method->getNumberOfParameters());
    }

    protected function getPropertyType(string $type): string|array
    {
        $tsType = match ($type) {
            'tinyint' => TypeScriptType::BOOLEAN,
            'longtext', 'text', 'varchar', 'timestamp', 'datetime', 'date' => TypeScriptType::STRING,
            'int', 'bigint', 'double', 'decimal' => TypeScriptType::NUMBER,
            'json' => [TypeScriptType::array(), TypeScriptType::ANY],
            default => TypeScriptType::ANY
        };

        return $tsType;
    }

    protected function getRelationType(ReflectionMethod $method): string
    {
        $relationReturn = $method->invoke($this->model);
        $related = str_replace('\\', '.', get_class($relationReturn->getRelated()));
        $related = str_replace(
            'App.',
            config('puddleglum.namespace', 'Puddleglum').'.',
            $related,
        );

        if ($this->isManyRelation($method)) {
            if ($this->supportsPivotColumns($method)) {
                $related .= ' & { pivot: { [key: string]: any } }';
            }

            return TypeScriptType::array($related);
        }

        if ($this->isOneRelation($method)) {
            return $related;
        }

        return TypeScriptType::ANY;
    }

    protected function isManyRelation(ReflectionMethod $method): bool
    {
        $relationType = get_class($method->invoke($this->model));

        return in_array($relationType, [
            HasMany::class,
            BelongsToMany::class,
            HasManyThrough::class,
            MorphMany::class,
            MorphToMany::class,
        ]);
    }

    protected function supportsPivotColumns(ReflectionMethod $method): bool
    {
        $relationType = get_class($method->invoke($this->model));

        return in_array($relationType, [BelongsToMany::class, MorphToMany::class]);
    }

    protected function isOneRelation(ReflectionMethod $method): bool
    {
        $relationType = get_class($method->invoke($this->model));

        return in_array($relationType, [
            HasOne::class,
            BelongsTo::class,
            MorphOne::class,
            HasOneThrough::class,
        ]);
    }
}
