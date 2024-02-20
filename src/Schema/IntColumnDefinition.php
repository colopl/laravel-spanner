<?php

declare(strict_types=1);

namespace Colopl\Spanner\Schema;

use Illuminate\Database\Schema\ColumnDefinition;

/**
 * @property string $name
 * @property string|null $useSequence
 */
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
        $this->useSequence = $name ?? $this->createDefaultSequence();
        return $this;
    }

    /**
     * @return string
     */
    protected function createDefaultSequence(): string
    {
        $definition = $this->blueprint->createSequence($this->createSequenceName());
        $definition->startWithCounter(random_int(1, 1000000));
        return $definition->sequence;
    }

    /**
     * @return string
     */
    protected function createSequenceName(): string
    {
        return $this->blueprint->getPrefix()
            . $this->blueprint->getTable()
            . '_'
            . $this->name
            . '_sequence';
    }

}
