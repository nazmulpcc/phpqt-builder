<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Build\EnumHolderConstant;
use QtBuilder\Build\EnumHolderDefinition;

readonly class EnumHolderContext
{
    /** @var list<string> */
    public array $namespaceParts;

    /**
     * @param list<array{name: string, stubType: string, stubValue: string, cDecl: string}> $constants
     */
    public function __construct(
        public string $phpNamespace,
        public string $phpClassName,
        public string $filePrefix,
        public string $minitName,
        public string $ceVarName,
        public array $constants,
        public bool $isFlagAlias,
        public ?string $sourceCppType,
    ) {
        $this->namespaceParts = array_values(array_filter(
            preg_split('/\\\\+/', $this->phpNamespace) ?: [],
            static fn(string $part): bool => $part !== '',
        ));
    }

    public static function fromDefinition(EnumHolderDefinition $definition): self
    {
        return new self(
            phpNamespace: $definition->phpNamespace,
            phpClassName: $definition->phpClassName,
            filePrefix: $definition->filePrefix(),
            minitName: $definition->minitName(),
            ceVarName: $definition->ceVarName(),
            constants: array_map(
                static fn(EnumHolderConstant $constant): array => [
                    'name' => $constant->name,
                    'stubType' => self::stubType($constant->value),
                    'stubValue' => self::stubValue($constant->value),
                    'cDecl' => self::cDecl($constant),
                ],
                $definition->constants,
            ),
            isFlagAlias: $definition->isFlagAlias,
            sourceCppType: $definition->sourceCppType,
        );
    }

    private static function stubType(int|float|string $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            default => 'string',
        };
    }

    private static function stubValue(int|float|string $value): string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.12F', $value), '0'), '.'),
            default => var_export($value, true),
        };
    }

    private static function cDecl(EnumHolderConstant $constant): string
    {
        if (is_int($constant->value)) {
            return sprintf(
                'zend_declare_class_constant_long(%s, "%s", sizeof("%s") - 1, %s);',
                '%s',
                $constant->name,
                $constant->name,
                (string) $constant->value,
            );
        }

        if (is_float($constant->value)) {
            return sprintf(
                'zend_declare_class_constant_double(%s, "%s", sizeof("%s") - 1, %s);',
                '%s',
                $constant->name,
                $constant->name,
                rtrim(rtrim(sprintf('%.12F', $constant->value), '0'), '.'),
            );
        }

        return sprintf(
            'zend_declare_class_constant_string(%s, "%s", sizeof("%s") - 1, %s);',
            '%s',
            $constant->name,
            $constant->name,
            var_export($constant->value, true),
        );
    }
}
