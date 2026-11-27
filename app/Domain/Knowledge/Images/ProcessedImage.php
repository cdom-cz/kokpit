<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

/**
 * An upload after the pipeline: the bytes GD encoded itself, their format, the pixel size read back from those
 * bytes and the SHA-256 of the bytes as a lower-case hex string.
 */
final readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public ImageFormat $format,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}

    /**
     * The MIME type the stored file is served with.
     */
    public function mimeType(): string
    {
        return $this->format->value;
    }

    /**
     * The size of the stored file in bytes.
     */
    public function size(): int
    {
        return strlen($this->bytes);
    }
}
