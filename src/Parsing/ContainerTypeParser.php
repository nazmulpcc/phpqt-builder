<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use QtBuilder\Definition\ContainerType;

class ContainerTypeParser
{
    public function parse(string $cppType): ?ContainerType
    {
        $normalized = $this->normalizeOuterType($cppType);
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'QStringList' => new ContainerType($cppType, 'sequence', 'QStringList', 'QString'),
            'QVariantList' => new ContainerType($cppType, 'sequence', 'QVariantList', 'QVariant'),
            'QVariantMap' => new ContainerType($cppType, 'map', 'QVariantMap', keyType: 'QString', valueType: 'QVariant'),
            'QModelIndexList' => new ContainerType($cppType, 'sequence', 'QModelIndexList', 'QModelIndex'),
            default => $this->parseTemplateContainer($cppType, $normalized),
        };
    }

    private function parseTemplateContainer(string $rawType, string $normalized): ?ContainerType
    {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_:]*)\s*<(.+)>$/', $normalized, $matches)) {
            return null;
        }

        $containerName = $matches[1];
        $inner = trim($matches[2]);
        $args = $this->splitTopLevelArgs($inner);

        return match ($containerName) {
            'QList', 'QVector' => count($args) === 1
                ? new ContainerType($rawType, 'sequence', $containerName, trim($args[0]))
                : null,
            'QMap' => count($args) === 2
                ? new ContainerType($rawType, 'map', $containerName, keyType: trim($args[0]), valueType: trim($args[1]))
                : null,
            'QHash' => count($args) === 2
                ? new ContainerType($rawType, 'hash', $containerName, keyType: trim($args[0]), valueType: trim($args[1]))
                : null,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelArgs(string $args): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];

            if ($char === '<') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === '>') {
                $depth--;
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function normalizeOuterType(string $type): string
    {
        $type = trim($type);
        $type = preg_replace('/^\s*const\b\s*/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');

        return trim($type);
    }
}
