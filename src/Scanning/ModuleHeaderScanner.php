<?php

declare(strict_types=1);

namespace QtBuilder\Scanning;

use QtBuilder\Qt\QtInstallation;
use RuntimeException;

class ModuleHeaderScanner
{
    /**
     * @return list<HeaderCandidate>
     */
    public function scan(QtInstallation $installation, string $module): array
    {
        $headerRoot = $installation->headerRootFor($module);
        if ($headerRoot === null || !is_dir($headerRoot)) {
            throw new RuntimeException(sprintf('Module header root not found for %s.', $module));
        }

        $entries = scandir($headerRoot);
        if ($entries === false) {
            return [];
        }

        $candidates = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $publicHeader = $headerRoot . '/' . $entry;
            if (!is_file($publicHeader)) {
                continue;
            }

            if (!preg_match('/^Q[A-Z][A-Za-z0-9_]*$/', $entry)) {
                continue;
            }

            $parseHeader = $this->resolveParseHeader($publicHeader);
            $candidates[] = new HeaderCandidate(
                module: $module,
                className: $entry,
                publicHeader: $publicHeader,
                parseHeader: $parseHeader,
            );
        }

        usort(
            $candidates,
            static fn(HeaderCandidate $a, HeaderCandidate $b): int => strcmp($a->className, $b->className),
        );

        return $candidates;
    }

    private function resolveParseHeader(string $publicHeader): string
    {
        $content = file_get_contents($publicHeader);
        if ($content === false) {
            return $publicHeader;
        }

        if (preg_match('/#include\s+"([^"]+)"/', $content, $matches) !== 1) {
            return $publicHeader;
        }

        $parseHeader = dirname($publicHeader) . '/' . $matches[1];

        return is_file($parseHeader) ? $parseHeader : $publicHeader;
    }
}
