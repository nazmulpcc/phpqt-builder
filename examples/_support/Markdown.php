<?php

declare(strict_types=1);

namespace Examples\Support;

final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $html = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                continue;
            }

            if (preg_match('/^###\s+(.*)$/', $trimmed, $matches)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<h3>' . self::inline($matches[1]) . '</h3>';
                continue;
            }

            if (preg_match('/^##\s+(.*)$/', $trimmed, $matches)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<h2>' . self::inline($matches[1]) . '</h2>';
                continue;
            }

            if (preg_match('/^#\s+(.*)$/', $trimmed, $matches)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<h1>' . self::inline($matches[1]) . '</h1>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $matches)) {
                if (!$inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>' . self::inline($matches[1]) . '</li>';
                continue;
            }

            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            $html[] = '<p>' . self::inline($trimmed) . '</p>';
        }

        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text) ?? $text;

        return $text;
    }
}
