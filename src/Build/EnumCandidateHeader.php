<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EnumCandidateHeader
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        public string $header,
        public string $module,
        public array $types,
    ) {}

    /**
     * @return array{header: string, module: string, types: list<string>}
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'module' => $this->module,
            'types' => $this->types,
        ];
    }
}
