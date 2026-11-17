<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class TimedSubtitles
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function mimeType(string $filename, string $detected): string
    {
        if (! in_array($detected, ['text/plain', 'application/x-subrip', 'application/x-srt', 'text/vtt'], true)) {
            return $detected;
        }

        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'srt' => 'application/x-subrip',
            'vtt' => 'text/vtt',
            default => $detected,
        };
    }

    public static function valid(string $text, string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, ['srt', 'vtt'], true) || strlen($text) > self::MAX_BYTES || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            return false;
        }

        $text = trim(str_replace(["\xEF\xBB\xBF", "\r\n", "\r"], ['', "\n", "\n"], $text));

        if ($extension === 'vtt') {
            if (! preg_match('/\AWEBVTT[^\n]*(?:\n\n|\z)/', $text, $header)) {
                return false;
            }

            $text = substr($text, strlen($header[0]));
        }

        $count = 0;
        $previous = -1;

        foreach (preg_split('/\n[\t ]*\n+/', $text) as $block) {
            if ($extension === 'vtt' && preg_match('/\A(?:NOTE|STYLE|REGION)(?:\s|\z)/', $block)) {
                continue;
            }

            $lines = explode("\n", trim($block));

            if (! str_contains($lines[0], '-->')) {
                if ($extension === 'srt' && ! ctype_digit($lines[0])) {
                    return false;
                }

                array_shift($lines);
            }

            $timestamp = array_shift($lines) ?? '';
            $pattern = '/\A((?:\d{2,}:)?[0-5]\d:[0-5]\d[.,]\d{3})[\t ]+-->[\t ]+((?:\d{2,}:)?[0-5]\d:[0-5]\d[.,]\d{3})(?:[\t ].*)?\z/';

            if (! preg_match($pattern, $timestamp, $times) || trim(implode("\n", $lines)) === '') {
                return false;
            }

            if ($extension === 'srt' && (! preg_match('/\A\d{2,}:\d{2}:\d{2},\d{3}\z/', $times[1]) || ! preg_match('/\A\d{2,}:\d{2}:\d{2},\d{3}\z/', $times[2]))) {
                return false;
            }

            if ($extension === 'vtt' && (str_contains($times[1], ',') || str_contains($times[2], ','))) {
                return false;
            }

            $start = self::milliseconds($times[1]);

            if ($start < $previous || self::milliseconds($times[2]) <= $start) {
                return false;
            }

            $previous = $start;
            $count++;
        }

        return $count > 0;
    }

    public static function assertValid(string $text, string $filename, string $key = 'media'): void
    {
        if (! self::valid($text, $filename)) {
            throw ValidationException::withMessages([$key => __('posts.form.youtube.subtitle_invalid')]);
        }
    }

    private static function milliseconds(string $timestamp): int
    {
        $parts = array_reverse(explode(':', str_replace(',', '.', $timestamp)));

        return (int) round(((float) $parts[0] + (int) $parts[1] * 60 + (int) ($parts[2] ?? 0) * 3600) * 1000);
    }
}
