<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

/**
 * The one parser and builder of the internal image reference of a knowledge body: `![alt](kokpit-attachment:<uuid>)`
 * (D-03). The renderer, the save path, the retention rules and the editor all read and write the reference through
 * this class, so the scheme and the uuid shape live in one place.
 *
 * in() is deliberately a superset of what the renderer shows: it also matches a reference inside a code block or a
 * link. A false "referenced" only keeps an image a little longer, whereas a false "unreferenced" would delete an image
 * that is visible in the article.
 */
final class ImageReferences
{
    public const string SCHEME = 'kokpit-attachment:';

    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}(?![0-9a-fA-F])';

    /**
     * Every uuid that follows the scheme anywhere in the text: lowercased, unique, in order of first occurrence.
     *
     * @return list<string>
     */
    public static function in(string $markdown): array
    {
        if (preg_match_all('#'.preg_quote(self::SCHEME, '#').'('.self::UUID.')#', $markdown, $matches) === false) {
            return [];
        }

        $ids = [];

        foreach ($matches[1] as $match) {
            $ids[strtolower($match)] = true;
        }

        return array_keys($ids);
    }

    /**
     * The lowercased uuid when the whole URL is exactly the scheme plus a uuid, otherwise null.
     */
    public static function idFromUrl(string $url): ?string
    {
        if (preg_match('#\A'.preg_quote(self::SCHEME, '#').'('.self::UUID.')\z#', $url, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * The Markdown of an image reference. The caller passes an alt text that is already sanitised.
     */
    public static function markdown(string $id, string $alt): string
    {
        return '!['.$alt.']('.self::SCHEME.$id.')';
    }
}
