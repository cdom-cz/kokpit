<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Reads the activity log allowlist declaration of Eloquent models (D-06).
 *
 * A model that logs activity must (a) use the LogsAllowlistedActivity wrapper,
 * (b) not override getActivitylogOptions(), (c) carry a non-empty
 * #[LoggedAttributes] on the class itself, (d) list only real columns and
 * (e) list no wildcard, no relation or JSON path, no hidden attribute and no
 * sensitive name. Rules (a), (b), (c), (e) need no database; rule (d) needs the
 * column list of the model's table and is only checked when it is passed in.
 */
final class AuditDeclaration
{
    /**
     * Names that must never reach the activity log: passwords, remember and API
     * tokens, two-factor material and anything ending in _secret or _token.
     */
    private const string SENSITIVE_NAME = '/^(password|secret|token|remember_token)$|^password_|_password$|^two_factor|_secret$|_token$/i';

    /**
     * Every concrete class under the given directory that logs activity through
     * the package trait; without a directory, every application model.
     *
     * @param  string|null  $directory  absolute directory to scan instead of the application models
     * @param  string  $namespace  namespace the directory maps to (PSR-4), with trailing backslash
     * @return list<class-string<Model>>
     */
    public static function loggingModels(?string $directory = null, string $namespace = ''): array
    {
        $candidates = $directory === null
            ? ModelDeclaration::appModels()
            : self::classesIn($directory, $namespace);

        $classes = [];

        foreach ($candidates as $class) {
            if (self::logsActivity($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * What is wrong with the allowlist declaration of a class; empty when it is fine.
     *
     * @param  class-string  $class
     * @param  list<string>|null  $columns  real columns of the model's table, or null to skip rule (d)
     * @return list<string>
     */
    public static function problems(string $class, ?array $columns = null): array
    {
        $reflection = new ReflectionClass($class);
        $label = $reflection->isAnonymous() ? 'anonymous class' : $class;
        $problems = [];

        if (! in_array(LogsAllowlistedActivity::class, class_uses_recursive($class), true)) {
            $problems[] = "{$label} logs activity without the LogsAllowlistedActivity wrapper trait";
        } elseif (self::overridesOptions($reflection)) {
            $problems[] = "{$label} overrides getActivitylogOptions(), which would bypass the allowlist";
        }

        // Attributes are not inherited: only the one written on the class itself counts.
        $declared = $reflection->getAttributes(LoggedAttributes::class);

        if ($declared === []) {
            return [...$problems, "{$label} declares no #[LoggedAttributes] allowlist on the class itself"];
        }

        $attributes = $declared[0]->newInstance()->attributes;

        if ($attributes === []) {
            return [...$problems, "{$label} declares an empty #[LoggedAttributes] allowlist"];
        }

        $hidden = $reflection->isSubclassOf(Model::class) ? (new $class)->getHidden() : [];

        foreach ($attributes as $attribute) {
            foreach (self::attributeProblems($attribute, $hidden, $columns) as $problem) {
                $problems[] = "{$label} allowlists '{$attribute}': {$problem}";
            }
        }

        return $problems;
    }

    /**
     * What is wrong with one allowlisted attribute name.
     *
     * @param  list<string>  $hidden  attributes hidden on the model
     * @param  list<string>|null  $columns  real columns of the model's table, or null to skip rule (d)
     * @return list<string>
     */
    public static function attributeProblems(string $attribute, array $hidden = [], ?array $columns = null): array
    {
        $problems = [];

        if ($attribute === '*') {
            $problems[] = 'a wildcard logs everything';
        }

        if (str_contains($attribute, '.')) {
            $problems[] = 'a dotted relation path is not a column of the model';
        }

        if (str_contains($attribute, '->')) {
            $problems[] = 'a JSON path can reach nested secrets';
        }

        if (in_array($attribute, $hidden, true)) {
            $problems[] = 'the attribute is hidden on the model';
        }

        if (preg_match(self::SENSITIVE_NAME, $attribute) === 1) {
            $problems[] = 'the name is sensitive (password, token, secret or two-factor material)';
        }

        if ($columns !== null && ! in_array($attribute, $columns, true)) {
            $problems[] = 'the attribute is not a column of the model table';
        }

        return $problems;
    }

    /**
     * @param  class-string  $class
     */
    private static function logsActivity(string $class): bool
    {
        return in_array(LogsActivity::class, class_uses_recursive($class), true);
    }

    /**
     * Whether the model resolves getActivitylogOptions() to something other than
     * the wrapper trait: a body written on the model or on a parent class.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private static function overridesOptions(ReflectionClass $reflection): bool
    {
        $method = new ReflectionMethod($reflection->getName(), 'getActivitylogOptions');
        $wrapperFile = (new ReflectionClass(LogsAllowlistedActivity::class))->getFileName();

        return $method->getFileName() !== $wrapperFile;
    }

    /**
     * @return list<class-string>
     */
    private static function classesIn(string $directory, string $namespace): array
    {
        $classes = [];
        $root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $directory), '/');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            $class = $namespace.str_replace('/', '\\', substr($path, strlen($root) + 1, -4));

            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
