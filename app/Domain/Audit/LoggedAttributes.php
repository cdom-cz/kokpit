<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Attribute;

/**
 * Declares which attributes of a model may reach the activity log (D-06).
 *
 * The list lives on the class itself, so it can be read by reflection without
 * instantiating the model. Attributes are not inherited: every concrete model
 * that logs activity declares its own list. The LogsAllowlistedActivity trait
 * reads it and refuses to log when it is missing or empty.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class LoggedAttributes
{
    /**
     * @param  list<string>  $attributes  Column names that may be written to the log.
     */
    public function __construct(public readonly array $attributes) {}
}
