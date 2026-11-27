<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use InvalidArgumentException;

/**
 * The caller's statement of which images the renderer may show in one render() call (D-13): a lowercase uuid maps
 * to the route path of the image and its stored size. The context is built outside the renderer, from the rendered
 * article (and, in the editor preview only, the actor's own pending images), so the renderer stays free of the
 * application and holds no model. A reference whose uuid is not listed here renders as text, never as an image.
 */
final readonly class ImageContext
{
    /**
     * @param  array<string, array{src: string, width: int, height: int}>  $images  keyed by lowercase uuid
     */
    private function __construct(private array $images) {}

    /**
     * The context of a render that may show no image.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, array{src: string, width: int, height: int}>  $images  keyed by uuid
     *
     * @throws InvalidArgumentException when a src is not the internal image route or a size is below 1
     */
    public static function of(array $images): self
    {
        $checked = [];

        foreach ($images as $id => $image) {
            if (preg_match(InternalImageSrcSanitizer::PATTERN, $image['src']) !== 1) {
                throw new InvalidArgumentException('An image src must be the internal image route.');
            }

            if ($image['width'] < 1 || $image['height'] < 1) {
                throw new InvalidArgumentException('An image width and height must be at least 1.');
            }

            $checked[strtolower((string) $id)] = [
                'src' => $image['src'],
                'width' => $image['width'],
                'height' => $image['height'],
            ];
        }

        return new self($checked);
    }

    /**
     * @return array{src: string, width: int, height: int}|null
     */
    public function find(string $id): ?array
    {
        return $this->images[strtolower($id)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->images);
    }
}
