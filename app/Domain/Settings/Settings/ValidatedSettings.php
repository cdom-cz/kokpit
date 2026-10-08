<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionNamedType;
use Spatie\LaravelSettings\Settings;

/**
 * Base of every typed settings class: the rules live on the data layer, so a
 * value that bypasses the settings form (a command, a job, a test) is checked
 * by the same rules before it reaches the table (D-02).
 *
 * The form talks to the class through two seams: toFormState() (class to form
 * state) and fillFromFormState() (form state to class). Both default to the
 * plain property mapping; a class with a different form shape overrides them.
 */
abstract class ValidatedSettings extends Settings
{
    /**
     * Laravel validation rules keyed by form-state key.
     *
     * @return array<string, list<mixed>>
     */
    abstract public static function rules(): array;

    /**
     * @return array<string, mixed>
     */
    public function toFormState(): array
    {
        return $this->toArray();
    }

    /**
     * Fills the declared properties from form state and ignores every other
     * key. Text fields arrive from a form as null when empty: a nullable
     * property keeps null, a required-type property gets an empty string.
     *
     * @param  array<string, mixed>  $state
     */
    public function fillFromFormState(array $state): static
    {
        $properties = $this->settingsConfig()->getReflectedProperties();
        $values = [];

        foreach ($state as $name => $value) {
            $property = $properties->get($name);

            if ($property === null) {
                continue;
            }

            $type = $property->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
                $value = match (true) {
                    $value === null || $value === '' => $type->allowsNull() ? null : '',
                    default => $value,
                };
            }

            $values[$name] = $value;
        }

        $this->fill($values);

        return $this;
    }

    /**
     * Validates the current values against rules() and only then writes them.
     *
     * @throws ValidationException
     */
    public function save(): static
    {
        Validator::make($this->toFormState(), static::rules())->validate();

        parent::save();

        return $this;
    }
}
