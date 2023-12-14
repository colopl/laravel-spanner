# v6.2.0 (Not Released Yet)

Added
- `json` `mediumText` `longText` `char` support for `Schema\Builder` (#155) (#158)
- `Schema\Grammar::compileDropForeign` to allow dropping foreign key constraints (#163)
- `Schema\Builder::dropAllTables` works properly, dropping foreign keys, indexes, then tables in order of interleaving (#161)

Changed
- `Query\Builder::lock()` no longer throw an error and will be ignored instead (#156)
- `Schema\Builder::getIndexListing()` `Schema\Grammar::compileIndexListing()` converted to `getIndexes()` and `compileIndexes()` to align with standard Laravel methods (#161)

Fixed
- `Schema\Grammar::compileAdd()` `Schema\Grammar::compileChange()` `Schema\Grammar::compileChange()` now create separate statements (#159)

# v6.1.1 (2023-12-11)

Fixed
- Bug where auth and session pool writing to the same file may cause race condition (#152)

# v6.1.0 (2023-11-29)

Added
- Add support for [NUMERIC](https://cloud.google.com/spanner/docs/reference/standard-sql/data-types#numeric_type) column type. (#145)

Fixed
- Match internals so that it lines up with laravel 10.34.0. (#150)

# v6.0.0 (2023-11-22)

Added
- Add [Data Boost](https://cloud.google.com/spanner/docs/databoost/databoost-overview) support (#131)
- Deprecation warnings to `Connection`'s methods `cursorWithTimestampBound` `selectWithTimestampBound` `selectOneWithTimestampBound`. Use `cursorWithOptions` `selectWithOptions` instead. (#122)
- `Connection` has new methods `selectWithOptions` `cursorWithOptions` which allows spanner specific options to be set for each query. (#122)
- `session:list` command can now show and filter by labels. (#134)
- Allow custom cache path (#142)

Changed
- [Breaking] Match `Query\Builder::forceIndex()` behavior with laravel's (`forceIndex` property no longer exists). (#114)
- [Breaking] SessionNotFoundErrorMode was removed and will always run clear session pool. (#132) (#130)
- [Breaking] Auth cache and Session pool now share the same file cache adapter (#139)

# v5.3.0 (2023-11-17)

Fixed
- Explicitly stage/clear transaction on commit to correctly run afterCommit jobs in Laravel >= [v10.32.0](https://github.com/laravel/framework/pull/48859) (#144)

# v5.2.2 (2023-08-22)

Fixed
- Fixed a case where queries were not being retried on "Session Not Found" errors when session pool is undefined (#129)

# v5.2.1 (2023-08-16)

Fixed
- Escape list for `Query/Builder::toRawSql` (#127)

# v5.2.0

Added
- Added deprecation warnings to `Connection::runDdl` and `Connection::runDdls` (#98)
- Added  `ManagesMutations::insertOrUpdateUsingMutation` and `UsesMutations::insertOrUpdateUsingMutation` to do upserts (#109)
- Added Support for `Schema\Builder::dropIfExists()` (#115)
- Added support for adding row deletion policy when modifying table (#124)
- Added Support for `Query\Builder::toRawSql()` (#123)

Changed
- `Connection::waitForOperation` and `Connection::isDoneOperation` has been removed. (#99)
- Update `export-ignore` entries in `.gitattributes` (#104)
- Use abstract definitions on traits instead of relying on `@methods` `@property`. (#120)
- Stop using `call_user_func` (#121)

Fixed
- Transaction state was not being cleared if rolled back failed. (#107)
- Column was not escaped for clause `REPLACE ROW DELETION POLICY` (#125)

# v5.1.0

Added
- Added `Connection::runDdlBatch` which runs DDLs in batch synchronously. (#86)
- Added emulator support for `Connection::listSessions`. (#88)
- Added `Schema\Grammar::typeDouble` for better compatibility. (#97)

Fixed
- Fixed bug where running `Connection::statement` with DDLs was not logging and was not triggering events. (#86)
- FilesystemAdapter was not creating the directory for the cache file with proper permissions. (#93)

Changed
- Use google-cloud-php's CacheSessionPool since the [concerned bug](https://github.com/googleapis/google-cloud-php/issues/5567) has been fixed in [v1.53](https://github.com/googleapis/google-cloud-php-spanner/releases/tag/v1.58.2). (#90)
- Separate session pool and authentication per connection so transaction works properly. (#89)
- SessionPool and AuthCache now writes to `storage/framework/spanner/{$name}-{auth|session}`. (#93)

# v5.0.0

updated composer.json to only support laravel 10

Fixed
- `Connection::reconnectIfMissingConnection` was changed from `protected` to `public` to match laravel 10. (#77)
- [Query/Expression](https://laravel.com/docs/10.x/upgrade#database-expressions) changed from `(string)$expr` to `$expr->getValue($grammar)`. (#77)
- Applied [QueryException constructor change](https://laravel.com/docs/10.x/upgrade#query-exception-constructor) to `Schema/Grammar`. (#77)

Changed
- Checks that primary key is defined in schema and throws an exception if not defined. (#58)
- `Colopl\Spanner\Session` has been renamed to `Colopl\Spanner\SessionInfo`.
- `Blueprint::stringArray`'s `$length` parameter is now optional and defaults to `255`.
- Auth and session pool no longer use the custom FileCacheAdapter and uses Symfony's FilesystemAdapter instead. (#63)
- Path for auth and session pool files have moved from `storage/framework/cache/spanner` to `storage/framework/spanner/{auth|session}`. (#63)
- Default Session Not Found Error Mode was changed from `MAINTAIN_SESSION_POOL` to `CLEAR_SESSION_POOL` (wasn't fully confident at the time, but I think it should be safe to assume it's working now).
- Schema\Builder::getAllTables() now returns rows with `name` and `type` fields instead of list of strings (was implemented incorrectly). (#73)
- Exception previously thrown in `Query/Builder` for `sharedLock`, `lockForUpdate`, `insertGetId` was moved to `Query/Grammar`. (#76)
- Query/Builder::lock will now throw `BadMethodCallException` if called. Was ignored in previous versions. (#76)
- [Breaking Change] Commands are now only avaiable in cli mode (#81)
- Connections will now be closed after every job has been processed in the queue. (#80)

Refactored
- Rollback handling has been refactored to better readability. (#79)

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
