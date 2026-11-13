<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

final class ReportSpeechText
{
    /**
     * Strip markdown markers and emoji so speech synthesis sounds natural.
     */
    public static function plain(string $text): string
    {
        $plain = str_replace(["\r\n", "\r"], "\n", $text);
        $plain = preg_replace('/```[\s\S]*?```/u', ' ', $plain) ?? $plain;
        $plain = preg_replace('/`([^`]+)`/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\*([^*]+)\*/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/__([^_]+)__/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/_([^_]+)_/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/^#{1,6}\s+/mu', '', $plain) ?? $plain;
        $plain = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $plain) ?? $plain;
        $plain = str_replace(['*', '_', '#', '`', '~', '>', '|'], ' ', $plain);
        $plain = preg_replace('/[ \t]+/u', ' ', $plain) ?? $plain;
        $plain = preg_replace('/\n{3,}/u', "\n\n", $plain) ?? $plain;

        return trim($plain);
    }
}
