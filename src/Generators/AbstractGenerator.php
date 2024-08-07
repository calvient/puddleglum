<?php

namespace Calvient\Puddleglum\Generators;

use Calvient\Puddleglum\Contracts\Generator;
use ReflectionClass;

abstract class AbstractGenerator implements Generator
{
    protected string $filename = 'index.ts';

    protected string $fileImports = '';

    protected ReflectionClass $reflection;

    public function generate(ReflectionClass $reflection, ?string $namespace = null): ?string
    {

        $this->reflection = $reflection;
        $this->boot();

        if (empty(trim($definition = $this->getDefinition()))) {
            $definition = "";
        }

        return "export interface {$this->tsClassName()} { $definition }";
    }

    protected function boot(): void
    {
        //
    }

    protected function tsClassName(): string
    {
        return str_replace('\\', '.', $this->reflection->getShortName());
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getFileImports(): string
    {
        return $this->fileImports;
    }
}
