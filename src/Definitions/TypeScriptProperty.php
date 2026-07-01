<?php

namespace Calvient\Puddleglum\Definitions;

class TypeScriptProperty
{
    public function __construct(
        public string $name,
        public string|array $types,
        public bool $optional = false,
        public bool $readonly = false,
        public bool $nullable = false,
    ) {
    }

    public function getTypes(): string
    {
        $types = is_array($this->types) ? $this->types : [$this->types];

        if ($this->nullable) {
            $types[] = TypeScriptType::NULL;
        }

        return implode(' | ', $types);
    }

    public function __toString(): string
    {
        return ($this->readonly ? 'readonly ' : '') .
            $this->name .
            ($this->optional ? '?' : '') .
            ': ' .
            $this->getTypes() .
            ';';
    }
}
