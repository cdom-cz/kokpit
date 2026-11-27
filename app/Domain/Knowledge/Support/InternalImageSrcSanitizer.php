<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The img src rule of the knowledge sanitiser, the second layer of D-13: an image source survives only when it is
 * exactly the internal, authorised image route (the panel path plus `knowledge/attachments/` plus a lowercase uuid).
 * An absolute, protocol-relative, query-carrying or any other relative source is dropped, so no bucket URL and no
 * third-party image can reach a page. The sanitiser chain applies every matching attribute sanitiser and stops on
 * null, so the URL sanitiser can never re-admit what this one dropped.
 */
final class InternalImageSrcSanitizer implements AttributeSanitizerInterface
{
    /**
     * The `D` modifier makes the `$` mean the end of the value, not "before a final newline".
     */
    public const string PATTERN = '#^/admin/knowledge/attachments/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#D';

    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
