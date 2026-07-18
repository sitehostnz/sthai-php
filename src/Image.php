<?php

declare(strict_types=1);

namespace SthAI;

use SthAI\Exception\InvalidArgumentException;

/**
 * Helpers for building image_url content parts from URLs, local files or
 * raw image bytes. Local content is inlined as a base64 data URI, with the
 * MIME type sniffed from the image's magic bytes (the formats the API
 * accepts: PNG, JPEG, GIF and WEBP).
 */
final class Image
{
    private function __construct()
    {
    }

    /**
     * An image_url content part for a URL (or an existing data URI).
     *
     * @return array{type: string, image_url: array{url: string}}
     */
    public static function urlPart(string $url): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => $url]];
    }

    /**
     * An image_url content part for a local image file.
     *
     * @return array{type: string, image_url: array{url: string}}
     */
    public static function filePart(string $path): array
    {
        return self::urlPart(self::dataUriFromFile($path));
    }

    /**
     * An image_url content part for raw image bytes.
     *
     * @return array{type: string, image_url: array{url: string}}
     */
    public static function bytesPart(string $bytes): array
    {
        return self::urlPart(self::dataUriFromBytes($bytes));
    }

    /**
     * Base64-encode a local image file into a data URI.
     */
    public static function dataUriFromFile(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException(sprintf('unable to read image file: %s', $path));
        }

        return self::dataUriFromBytes($bytes);
    }

    /**
     * Base64-encode raw image bytes into a data URI, sniffing the MIME
     * type from the magic bytes.
     */
    public static function dataUriFromBytes(string $bytes): string
    {
        return sprintf('data:%s;base64,%s', self::sniffMime($bytes), base64_encode($bytes));
    }

    /**
     * The MIME type indicated by the image's magic bytes.
     */
    private static function sniffMime(string $bytes): string
    {
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strncmp($bytes, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, 'GIF8', 4) === 0) {
            return 'image/gif';
        }

        throw new InvalidArgumentException(
            'unrecognized image format: expected PNG, JPEG, GIF, or WEBP'
        );
    }
}
