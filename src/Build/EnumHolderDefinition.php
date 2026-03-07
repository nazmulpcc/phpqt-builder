<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EnumHolderDefinition
{
    /**
     * @param list<EnumHolderConstant> $constants
     */
    public function __construct(
        public string $module,
        public string $cppType,
        public string $phpNamespace,
        public string $phpClassName,
        public array $constants,
        public bool $isFlagAlias = false,
        public ?string $sourceCppType = null,
        public string $headerPath = '',
    ) {}

    public function filePrefix(): string
    {
        $parts = array_values(array_filter(
            preg_split('/\\\\+/', $this->phpNamespace . '\\' . $this->phpClassName) ?: [],
            static fn(string $part): bool => $part !== '',
        ));
        $snake = array_map(
            static function (string $part): string {
                $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $part) ?? $part;
                $value = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? $value;

                return strtolower(trim($value, '_'));
            },
            $parts,
        );

        return 'qt_enum_' . implode('_', array_values(array_filter($snake, static fn(string $part): bool => $part !== '')));
    }

    public function minitName(): string
    {
        return $this->filePrefix();
    }

    public function ceVarName(): string
    {
        return $this->filePrefix() . '_ce';
    }

    /**
     * @return array{
     *   module: string,
     *   cpp_type: string,
     *   php_namespace: string,
     *   php_class_name: string,
     *   is_flag_alias: bool,
     *   source_cpp_type: ?string,
     *   header: string,
     *   constants: list<array{name: string, value: int|float|string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'cpp_type' => $this->cppType,
            'php_namespace' => $this->phpNamespace,
            'php_class_name' => $this->phpClassName,
            'is_flag_alias' => $this->isFlagAlias,
            'source_cpp_type' => $this->sourceCppType,
            'header' => $this->headerPath,
            'constants' => array_map(
                static fn(EnumHolderConstant $constant): array => $constant->toArray(),
                $this->constants,
            ),
        ];
    }
}
