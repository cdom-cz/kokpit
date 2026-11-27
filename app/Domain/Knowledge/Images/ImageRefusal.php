<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

/**
 * Why an upload was refused (D-06, D-07, D-09). The value is the last part of the language key of the message.
 */
enum ImageRefusal: string
{
    case NotAnImage = 'not_an_image';
    case Svg = 'svg';
    case TooLarge = 'too_large';
    case TooManyPixels = 'too_many_pixels';
    case Animated = 'animated';
    case Failed = 'failed';

    /**
     * The language key of the message for the person who uploaded the file (the keys are written with the language
     * files of the image feature).
     */
    public function messageKey(): string
    {
        return 'knowledge.images.errors.'.$this->value;
    }
}
