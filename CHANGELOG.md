# v5.0.0 (Not Released Yet)

Changed
- Checks that primary key is defined in schema and throws an exception if not defined.
- `Colopl\Spanner\Session` has been renamed to `Colopl\Spanner\SessionInfo`.

# v4.7.0

Added
- Support `Blueprint::text` (translates to `STRING(MAX)`).

Chore
- Removed `ramsey/uuid` from composer.json since laravel already includes it.

Fixed
- Expressions given as $value in `Schema\Grammar::formatDefaultValue` will now go through `getValue` to match upstream (No behavioral change).

# v4.6.0

Fixed
- `Model::fresh` and `Model::refresh` now adds interleaved keys to the query.
- Remove Type declaration from `Query/Builder::forceIndex()` to match the one added to laravel/framework in `v9.52.0`.

# v4.5.0

Added
- Command `spanner:warmup` now has a new option `--skip-on-error` which will skip any connections which throws an exception.

Fixed
- Transaction state was not cleared if a NotFoundException was raised during rollback.

# v4.4.0

Added
- Support Schema\Builder::getAllTables()
- Command `spanner:cooldown` which clears all connections in the session pool.
- Command `spanner:warmup` now has a new option `--refresh` which will clear all existing sessions before warming up.
- Command `spanner:sessions` now has a new option `--sort` and `--order` which allows for sorting of results.

Changed
- Default SessionPool was changed from `Google\Cloud\Spanner\Session\CacheSessionPool` to `Colopl\Spanner\Session\CacheSessionPool` to patch an [unresolved issue on Google's end](https://github.com/googleapis/google-cloud-php/issues/5567).

Fixed
- SessionPool was not cleared if php terminated immediately after calling `CacheSessionPool::clear`.

# v4.3.0

Added
- Support for default values in table columns.
- Command `spanner:sessions` which will list the sessions on the server side.
- Command `spanner:warmup` which warms up sessions upto minimum number set in config.
- `TransactionCommitting` support has been added (NOTE: this is triggered only once at root on nested transactions).
- Replace and drop row deletion policy methods for Schema Builder.
- Action classes for interleave and index for IDE auto-completion.
- `Blueprint::interleaveInParent()` was added and `Blueprint::interleave()` has been deprecated.
- `IndexDefinition::interleaveIn()` was added and `IndexDefinition::interleave()` has been deprecated.

Fixed
- Array Column's type now gets parsed in `Schema/Grammar` instead of at blueprint.

Chore
- Unnecessary folder depth has been flattened.

# v4.2.0

Added
- `Colopl\Spanner\Query\Builder::truncate()` is now implemented. (use to throw an error) (a171e90d3b862a3b207582ab44f0d4009e12118c)

Fixed
- `Colopl\Spanner\Schema\Grammar::getDateFormat()` now returns `'Y-m-d\TH:i:s.uP'` instead of the default which does work correctly in Cloud Spanner. (43bffe630dbf765019674d12bf3ff3e768fe4022)
- `Colopl\Spanner\Schema\Grammar::wrapValue()` has been extracted as trait, so it can be shared with `Colopl\Spanner\Query\Grammar`. (2f4347942397b9197284f342e2a345bceec12402, ca8faa1db70934fd2809e00fbd640dd711e996f2)
- `Colopl\Spanner\Concerns\ManagesTransactions::handleBeginTransactionException()` now matches return type of parent. (7c9b5c305ab4b7e192e58af84aaa5b78caaebcb5)
