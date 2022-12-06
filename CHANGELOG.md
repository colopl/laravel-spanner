# v5.0.0

Added
- Command `spanner:warmup` which warms up sessions upto minimum number set in config.
- Command `spanner:cooldown` which clears all connections in the session pool.
- `TransactionCommitting` support has been added (NOTE: this is triggered only once at root on nested transactions).

Changed
- `Colopl\Spanner\Session` has been renamed to `Colopl\Spanner\SessionInfo`.
- Default SessionPool was changed from `Google\Cloud\Spanner\Session\CacheSessionPool` to `Colopl\Spanner\CacheSessionPool` to patch an [unresolved issue on google's end](https://github.com/googleapis/google-cloud-php/issues/5567).
- `Blueprint::stringArray`'s `$length` parameter is now optional and defaults to `255`.

Fixed
- SessionPool was not cleared if php terminated immediately after calling `CacheSessionPool::clear`.
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
