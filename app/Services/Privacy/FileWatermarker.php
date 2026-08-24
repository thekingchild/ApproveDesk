<?php

namespace App\Services\Privacy;

use ZipArchive;

final class FileWatermarker
{
    /**
     * @return array{name: string, contents: string, mime: string}
     */
    public function apply(string $contents, string $originalName, string $mime, string $notice): array
    {
        if ($this->isImage($mime)) {
            $overlay = $this->overlayImage($contents, $notice);

            if ($overlay !== null) {
                return [
                    'name' => $originalName,
                    'contents' => $overlay,
                    'mime' => $mime,
                ];
            }
        }

        return $this->wrapZip($contents, $originalName, $notice);
    }

    public function notice(string $downloader, string $reference): string
    {
        return implode("\n", [
            'ApproveDesk download notice',
            'Downloaded by: '.$downloader,
            'Request: '.$reference,
            'Downloaded at: '.now()->toIso8601String(),
            'Do not forward this file outside the people who already have access to the request.',
        ]);
    }

    private function isImage(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    private function overlayImage(string $contents, string $notice): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $line = $this->firstNoticeLine($notice);
        $font = 2;
        $padding = 4;
        $textWidth = imagefontwidth($font) * strlen($line);
        $textHeight = imagefontheight($font);
        $boxWidth = min($width, $textWidth + ($padding * 2));
        $boxHeight = $textHeight + ($padding * 2);
        $x = 0;
        $y = max(0, $height - $boxHeight);
        $overlay = imagecolorallocatealpha($image, 15, 15, 18, 40);
        $text = imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle($image, $x, $y, $x + $boxWidth, $height, $overlay);
        imagestring($image, $font, $x + $padding, $y + $padding, substr($line, 0, (int) floor(($boxWidth - $padding) / imagefontwidth($font))), $text);

        ob_start();
        imagepng($image);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded !== '' ? $encoded : null;
    }

    /**
     * @return array{name: string, contents: string, mime: string}
     */
    private function wrapZip(string $contents, string $originalName, string $notice): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adw');

        if ($tmp === false) {
            throw new \RuntimeException('Unable to create a watermarked download.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to create a watermarked download.');
        }

        $zip->addFromString($originalName, $contents);
        $zip->addFromString('DOWNLOAD-NOTICE.txt', $notice);
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        $base = pathinfo($originalName, PATHINFO_FILENAME);

        return [
            'name' => ($base !== '' ? $base : 'download').'-download.zip',
            'contents' => $bytes,
            'mime' => 'application/zip',
        ];
    }

    private function firstNoticeLine(string $notice): string
    {
        $lines = preg_split('/\R/', $notice) ?: [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'Downloaded by:')) {
                return $line;
            }
        }

        return $lines[0] ?? 'ApproveDesk download';
    }
}
