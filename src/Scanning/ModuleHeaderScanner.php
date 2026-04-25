<?php

declare(strict_types=1);

namespace QtBuilder\Scanning;

use QtBuilder\Qt\QtInstallation;
use QtBuilder\Support\SmartPointerAliasResolver;
use RuntimeException;

class ModuleHeaderScanner
{
    public function __construct(
        private readonly SmartPointerAliasResolver $smartPointerAliasResolver = new SmartPointerAliasResolver(),
    ) {}

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
            if ($this->smartPointerAliasResolver->resolve($parseHeader, $entry) !== null) {
                continue;
            }
            if ($this->isTypeAliasForwarder($parseHeader, $entry)) {
                continue;
            }
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

        if (preg_match('/^\s*#\s*include\s*[<"]([^">]+)[">]/m', $content, $matches) !== 1) {
            return $publicHeader;
        }

        $includeTarget = trim((string) ($matches[1] ?? ''));
        if ($includeTarget === '') {
            return $publicHeader;
        }

        $headerDir = dirname($publicHeader);
        $candidates = [$headerDir . '/' . $includeTarget];
        if (str_contains($includeTarget, '/')) {
            $candidates[] = $headerDir . '/' . basename($includeTarget);
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $publicHeader;
    }

    private function isTypeAliasForwarder(string $parseHeader, string $symbol): bool
    {
        $content = @file_get_contents($parseHeader);
        if (!is_string($content) || $content === '') {
            return false;
        }

        if (preg_match('/\b(?:class|struct)\s+' . preg_quote($symbol, '/') . '\b/', $content) === 1) {
            return false;
        }

        if (preg_match('/\btypedef\b[^;]*\b' . preg_quote($symbol, '/') . '\b\s*;/', $content) === 1) {
            return true;
        }

        return preg_match('/\busing\s+' . preg_quote($symbol, '/') . '\s*=\s*[^;]+;/', $content) === 1;
    }
}
