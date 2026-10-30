# Phase 14 deferred items

Out-of-scope discoveries logged during execution. Not fixed by the plan that found them.

## Found in plan 14-01

- `app/Console/Commands/ResetAdminTwoFactorCommand.php` line 39: PHPStan reports
  `function.alreadyNarrowedType` ("Call to function is_string() with string will always
  evaluate to true"). The file is not touched by Phase 14; the error is present before
  plan 14-01 and makes `phpstan analyse` exit non-zero for the whole project. Plan 14-01
  verified that it is the only reported error. Needs a fix (or a baseline entry) outside
  Phase 14 so the PHPStan gate of later plans can be read as clean.
  status: acknowledged
