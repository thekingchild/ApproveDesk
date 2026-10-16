<?php

namespace App\Support;

/**
 * Escapes user-supplied text before it is merged into Markdown mail, so a
 * request title like "[Sign in](https://phish.example)" stays literal text
 * instead of becoming a link in a genuine ApproveDesk email (RV-SEC-14).
 */
final class MarkdownText
{
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()<>#+!|~-])/', '\\\\$1', $text);
    }
}
