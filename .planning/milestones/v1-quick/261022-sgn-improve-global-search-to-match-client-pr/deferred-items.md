# Deferred items (quick 261022-sgn)

- `ddev composer stan` reports one error outside this task's files:
  `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (`function.alreadyNarrowedType`, `is_string()` on a value already typed as string).
  The file is untouched by this task. It most likely appears since the uncommitted dependency bump in `composer.lock`
  (pre-existing working tree change). Not fixed here (out of scope); the files touched by this task analyse clean.
