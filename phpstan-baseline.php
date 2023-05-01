<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:__construct\\(\\) has parameter \\$config with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:affectingStatement\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:cursor\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:insertUsingMutation\\(\\) has parameter \\$dataSet with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:prepareBindings\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:prepareBindings\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:prepareForMutation\\(\\) has parameter \\$dataSet with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:prepareForMutation\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:runPartitionedDml\\(\\) should return int but returns mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:runQueryCallback\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:select\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:select\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:select\\(\\) should return array but returns mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:selectWithTimestampBound\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:selectWithTimestampBound\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:statement\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Connection\\:\\:updateUsingMutation\\(\\) has parameter \\$dataSet with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$connection of method Illuminate\\\\Database\\\\DatabaseTransactionsManager\\:\\:begin\\(\\) expects string, string\\|null given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$connection of method Illuminate\\\\Database\\\\DatabaseTransactionsManager\\:\\:commit\\(\\) expects string, string\\|null given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$pdo of method Illuminate\\\\Database\\\\Connection\\:\\:__construct\\(\\) expects Closure\\|PDO, null given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$start of method Illuminate\\\\Database\\\\Connection\\:\\:getElapsedTime\\(\\) expects int, float given\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Return type \\(void\\) of method Colopl\\\\Spanner\\\\Connection\\:\\:getDoctrineConnection\\(\\) should be compatible with return type \\(Doctrine\\\\DBAL\\\\Connection\\) of method Illuminate\\\\Database\\\\Connection\\:\\:getDoctrineConnection\\(\\)$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Return type \\(void\\) of method Colopl\\\\Spanner\\\\Connection\\:\\:getPdo\\(\\) should be compatible with return type \\(PDO\\) of method Illuminate\\\\Database\\\\Connection\\:\\:getPdo\\(\\)$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Return type \\(void\\) of method Colopl\\\\Spanner\\\\Connection\\:\\:getReadPdo\\(\\) should be compatible with return type \\(PDO\\) of method Illuminate\\\\Database\\\\Connection\\:\\:getReadPdo\\(\\)$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Return type \\(void\\) of method Colopl\\\\Spanner\\\\Connection\\:\\:setDatabaseName\\(\\) should be compatible with return type \\(\\$this\\(Illuminate\\\\Database\\\\Connection\\)\\) of method Illuminate\\\\Database\\\\Connection\\:\\:setDatabaseName\\(\\)$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Unable to resolve the template type TKey in call to function collect$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Using nullsafe method call on non\\-nullable type Illuminate\\\\Database\\\\DatabaseTransactionsManager\\. Use \\-\\> instead\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/src/Connection.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot cast mixed to int\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Eloquent/Model.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Eloquent\\\\Model\\:\\:insertAndSetId\\(\\) has parameter \\$attributes with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Eloquent/Model.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Events\\\\MutatingData\\:\\:__construct\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Events/MutatingData.php',
];
$ignoreErrors[] = [
	'message' => '#^Property Colopl\\\\Spanner\\\\Events\\\\MutatingData\\:\\:\\$values type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Events/MutatingData.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:deleteUsingMutation\\(\\) has parameter \\$keys with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:insert\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:insertUsingMutation\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:partitionedUpdate\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:runSelect\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:updateOrInsert\\(\\) has parameter \\$attributes with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:updateOrInsert\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Builder\\:\\:updateUsingMutation\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Grammar\\:\\:compileInsertGetId\\(\\) has parameter \\$values with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Grammar\\:\\:compileTruncate\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Grammar\\:\\:whereInUnnest\\(\\) has parameter \\$where with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Parameterizer\\:\\:parameterizeQuery\\(\\) has parameter \\$bindings with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Parameterizer.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Parameterizer\\:\\:parameterizeQuery\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Parameterizer.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Processor\\:\\:processColumnListing\\(\\) has parameter \\$results with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Processor.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Processor\\:\\:processColumnListing\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Processor.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Processor\\:\\:processIndexListing\\(\\) has parameter \\$results with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Processor.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Processor\\:\\:processIndexListing\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Processor.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Processor\\:\\:processSelect\\(\\) has parameter \\$results with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Processor.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Query\\\\Processor\\:\\:processSelect\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Query/Processor.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Schema\\\\Blueprint\\:\\:dropPrimary\\(\\) has parameter \\$index with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Blueprint.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Schema\\\\Builder\\:\\:createBlueprint\\(\\) should return Illuminate\\\\Database\\\\Schema\\\\Blueprint but returns mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Schema\\\\Builder\\:\\:getColumnListing\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Property Illuminate\\\\Database\\\\Schema\\\\Builder\\:\\:\\$resolver \\(Closure\\) in isset\\(\\) is not nullable\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Builder.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Colopl\\\\Spanner\\\\Schema\\\\IndexDefinition\\:\\:\\$columns\\.$#',
	'count' => 3,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Colopl\\\\Spanner\\\\Schema\\\\IndexDefinition\\:\\:\\$index\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Colopl\\\\Spanner\\\\Schema\\\\IndexDefinition\\:\\:\\$indexType\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\:\\:\\$column\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\:\\:\\$columns\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\:\\:\\$days\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\:\\:\\$onDelete\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\:\\:\\$policy\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\:\\:\\$table\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$arrayType\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$column\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$columns\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$days\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$default\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$index\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$length\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$nullable\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$policy\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$storedAs\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$useCurrent\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:\\$virtualAs\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Call to an undefined method Illuminate\\\\Support\\\\Fluent\\<string, mixed\\>\\:\\:default\\(\\)\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/Schema/Grammar.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\SpannerServiceProvider\\:\\:createSpannerConnection\\(\\) has parameter \\$config with no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/SpannerServiceProvider.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\TimestampBound\\\\ExactStaleness\\:\\:transactionOptions\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/TimestampBound/ExactStaleness.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\TimestampBound\\\\MaxStaleness\\:\\:transactionOptions\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/TimestampBound/MaxStaleness.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\TimestampBound\\\\MinReadTimestamp\\:\\:transactionOptions\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/TimestampBound/MinReadTimestamp.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\TimestampBound\\\\ReadTimestamp\\:\\:transactionOptions\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/TimestampBound/ReadTimestamp.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\TimestampBound\\\\StrongRead\\:\\:transactionOptions\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/TimestampBound/StrongRead.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\TimestampBound\\\\TimestampBoundInterface\\:\\:transactionOptions\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/src/TimestampBound/TimestampBoundInterface.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method get\\(\\) on mixed\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method listen\\(\\) on mixed\\.$#',
	'count' => 4,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Offset 1 does not exist on array\\<0, class\\-string\\>\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Offset 2 does not exist on array\\<0, class\\-string\\>\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$instance of method Google\\\\Cloud\\\\Spanner\\\\SpannerClient\\:\\:connect\\(\\) expects Google\\\\Cloud\\\\Spanner\\\\Instance\\|string, mixed given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#2 \\$keySet of method Colopl\\\\Spanner\\\\Connection\\:\\:deleteUsingMutation\\(\\) expects array\\|bool\\|float\\|Google\\\\Cloud\\\\Spanner\\\\KeySet\\|int\\|string, string\\|null given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#2 \\$name of method Google\\\\Cloud\\\\Spanner\\\\SpannerClient\\:\\:connect\\(\\) expects string, mixed given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/ConnectionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method set\\(\\) on mixed\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Console/WarmupCommandTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Access to an undefined property Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\Binding\\|Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\BindingChild\\:\\:\\$childId\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Call to an undefined method Illuminate\\\\Contracts\\\\Routing\\\\ResponseFactory\\|Illuminate\\\\Http\\\\Response\\:\\:noContent\\(\\)\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Call to an undefined method Illuminate\\\\Database\\\\Eloquent\\\\Relations\\\\BelongsTo\\:\\:firstOrFail\\(\\)\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access property \\$id on Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\Binding\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access property \\$pivot on Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\Tag\\|null\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access property \\$user on Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\UserItem\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method getKey\\(\\) on Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\Tag\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method map\\(\\) on array\\<Illuminate\\\\Database\\\\Eloquent\\\\Builder\\>\\|Illuminate\\\\Database\\\\Eloquent\\\\Collection\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\Tag\\:\\:items\\(\\) has no return type specified\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$userId of method Colopl\\\\Spanner\\\\Tests\\\\Eloquent\\\\ModelTest\\:\\:createTestUserInfo\\(\\) expects string, mixed given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#2 \\$haystack of method PHPUnit\\\\Framework\\\\Assert\\:\\:assertCount\\(\\) expects Countable\\|iterable, array\\<Illuminate\\\\Database\\\\Eloquent\\\\Builder\\>\\|Illuminate\\\\Database\\\\Eloquent\\\\Builder\\|Illuminate\\\\Database\\\\Eloquent\\\\Collection\\|Illuminate\\\\Database\\\\Eloquent\\\\Model\\|null given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Using nullsafe method call on non\\-nullable type Illuminate\\\\Foundation\\\\Application\\. Use \\-\\> instead\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Eloquent/ModelTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'bytesTest\' on object\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'count\' on mixed\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'intTest\' on object\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'name\' on object\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'nullableStringTest\' on object\\|null\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'timestampTest\' on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'userId\' on mixed\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'userId\' on object\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'userItemId\' on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method listen\\(\\) on mixed\\.$#',
	'count' => 5,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$expression of method Illuminate\\\\Database\\\\Query\\\\Builder\\:\\:selectRaw\\(\\) expects string, int given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#2 \\$haystack of method PHPUnit\\\\Framework\\\\Assert\\:\\:assertStringContainsString\\(\\) expects string, string\\|null given\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Query/BuilderTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'ts\' on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/DatetimeTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset 0 on mixed\\.$#',
	'count' => 6,
	'path' => __DIR__ . '/tests/Query/DatetimeTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method getTimestamp\\(\\) on DateTime\\|false\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/DatetimeTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$instance of method Google\\\\Cloud\\\\Spanner\\\\SpannerClient\\:\\:connect\\(\\) expects Google\\\\Cloud\\\\Spanner\\\\Instance\\|string, mixed given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/DatetimeTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$value of class Google\\\\Cloud\\\\Spanner\\\\Timestamp constructor expects DateTimeInterface, DateTime\\|false given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/DatetimeTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#2 \\$name of method Google\\\\Cloud\\\\Spanner\\\\SpannerClient\\:\\:connect\\(\\) expects string, mixed given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/DatetimeTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'int64Array\' on object\\|null\\.$#',
	'count' => 4,
	'path' => __DIR__ . '/tests/Query/SpannerArrayTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'numbers\' on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/SpannerArrayTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Method Colopl\\\\Spanner\\\\Tests\\\\Query\\\\SpannerArrayTest\\:\\:generateArrayTestRow\\(\\) return type has no value type specified in iterable type array\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/Query/SpannerArrayTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method format\\(\\) on mixed\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Schema/BlueprintTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method get\\(\\) on mixed\\.$#',
	'count' => 2,
	'path' => __DIR__ . '/tests/Schema/BlueprintTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method get\\(\\) on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/SessionNotFoundTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method getConnections\\(\\) on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/TestCase.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot call method set\\(\\) on mixed\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/TestCase.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot cast mixed to string\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/TestCase.php',
];
$ignoreErrors[] = [
	'message' => '#^Cannot access offset \'name\' on object\\|null\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/TransactionTest.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#1 \\$instance of method Google\\\\Cloud\\\\Spanner\\\\SpannerClient\\:\\:connect\\(\\) expects Google\\\\Cloud\\\\Spanner\\\\Instance\\|string, string\\|false given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/bootstrap.php',
];
$ignoreErrors[] = [
	'message' => '#^Parameter \\#2 \\$name of method Google\\\\Cloud\\\\Spanner\\\\SpannerClient\\:\\:connect\\(\\) expects string, string\\|false given\\.$#',
	'count' => 1,
	'path' => __DIR__ . '/tests/bootstrap.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
