<?php

declare(strict_types=1);

namespace SthAI\Tests;

use PHPUnit\Framework\TestCase;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Image;

final class ImageTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nrest-of-file";
    private const JPEG = "\xFF\xD8\xFF\xE0rest-of-file";
    private const GIF = 'GIF89arest-of-file';
    private const WEBP = "RIFF\x00\x00\x00\x00WEBPrest-of-file";

    public function testUrlStringPassesThrough(): void
    {
        $part = Image::urlPart('https://example.com/pic.png');
        $this->assertSame(
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/pic.png']],
            $part
        );
    }

    /**
     * @dataProvider magicByteProvider
     */
    public function testMagicBytesToDataUri(string $data, string $mime): void
    {
        $expected = sprintf('data:%s;base64,%s', $mime, base64_encode($data));
        $this->assertSame($expected, Image::dataUriFromBytes($data));
        $this->assertSame(
            ['type' => 'image_url', 'image_url' => ['url' => $expected]],
            Image::bytesPart($data)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function magicByteProvider(): array
    {
        return [
            'png' => [self::PNG, 'image/png'],
            'jpeg' => [self::JPEG, 'image/jpeg'],
            'gif' => [self::GIF, 'image/gif'],
            'webp' => [self::WEBP, 'image/webp'],
        ];
    }

    public function testFileInputReadFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sthai-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, self::PNG);

        try {
            $this->assertSame(Image::dataUriFromBytes(self::PNG), Image::dataUriFromFile($path));
            $this->assertSame(Image::bytesPart(self::PNG), Image::filePart($path));
        } finally {
            unlink($path);
        }
    }

    public function testUnreadableFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unable to read image file');
        Image::dataUriFromFile('/no/such/file.png');
    }

    public function testUnrecognizedFormatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unrecognized image format');
        Image::dataUriFromBytes('plain text, not an image');
    }
}
