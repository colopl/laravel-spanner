<?php

declare(strict_types=1);

namespace Colopl\Spanner\Schema;

use Colopl\Spanner\Support\Ensure;
use Illuminate\Database\Schema\ColumnDefinition;

class IntColumnDefinition extends ColumnDefinition
{
    public function __construct(protected Blueprint $blueprint, $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * Set the column to use a sequence.
     *
     * @param string|null $name
     * @return $this
     */
    public function useSequence(?string $name = null): static
    {
        $this->attributes['useSequence'] = $name ?? $this->createDefaultSequence();
        return $this;
    }

    /**
     * @return string
     */
    protected function createDefaultSequence(): string
    {
        $definition = $this->blueprint->createSequence($this->createSequenceName());
        return Ensure::string($definition->name);
    }

    /**
     * @return string
     */
    protected function createSequenceName(): string
    {
        return $this->blueprint->getPrefix()
            . $this->blueprint->getTable()
            . '_'
            . Ensure::string($this['name'])
            . '_sequence';
    }

}
