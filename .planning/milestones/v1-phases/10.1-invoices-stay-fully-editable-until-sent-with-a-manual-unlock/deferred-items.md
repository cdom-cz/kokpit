# Deferred items (phase 10.1)

Out-of-scope discoveries logged by executors; none of these was caused by a phase 10.1 change.

## Plan 10.1-01

- `ddev exec vendor/bin/phpstan analyse` reports one error in `app/Console/Commands/ResetAdminTwoFactorCommand.php`
  line 39 ("Call to function is_string() with string will always evaluate to true", `function.alreadyNarrowedType`).
  The file is untouched by this phase and the error is present before the first phase 10.1 commit. Left as it is.
  status: acknowledged
