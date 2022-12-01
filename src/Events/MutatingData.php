<?php

namespace Colopl\Spanner\Events;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEvent;

class MutatingData extends ConnectionEvent
{
    /**
     * @var string
     */
    public $tableName;

    /**
     * @var string
     */
    public $command;

    /**
     * @var array
     */
    public $values;

    /**
     * @param Connection $connection
     * @param string $tableName
     * @param string $command
     * @param array $values
     */
    public function __construct($connection, string $tableName, string $command, array $values)
    {
        parent::__construct($connection);

        $this->tableName = $tableName;
        $this->command = $command;
        $this->values = $values;
    }

}
