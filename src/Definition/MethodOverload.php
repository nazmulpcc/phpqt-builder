<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * Represents a single C++ method overload variant.
 *
 * A PHP method with overloaded C++ counterparts will hold one of these
 * for each original C++ signature. The code generator uses these to
 * emit dispatch logic (arg-count / type checks).
 */
readonly class MethodOverload
{
    /**
     * @param list<OverloadParameter> $parameters
     */
    public function __construct(
        public string $declaringClass,
        public string $returnType,
        public ?string $smartPointerReturnTargetCppType,
        public array $parameters,
        public string $access,
        public bool $isConst,
        public bool $isStatic,
        public bool $isVirtual,
        public bool $isPureVirtual,
    ) {}

    public function parameterCount(): int
    {
        return \count($this->parameters);
    }

    public function requiredParameterCount(): int
    {
        $count = 0;

        foreach ($this->parameters as $param) {
            if ($param->hasDefault) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @return array{declaring_class: string, return_type: string, smart_pointer_return_target_cpp_type: ?string, parameters: list<array<string, mixed>>, access: string, is_const: bool, is_static: bool, is_virtual: bool, is_pure_virtual: bool}
     */
    public function toArray(): array
    {
        return [
            'declaring_class' => $this->declaringClass,
            'return_type' => $this->returnType,
            'smart_pointer_return_target_cpp_type' => $this->smartPointerReturnTargetCppType,
            'parameters' => array_map(
                static fn(OverloadParameter $p): array => $p->toArray(),
                $this->parameters,
            ),
            'access' => $this->access,
            'is_const' => $this->isConst,
            'is_static' => $this->isStatic,
            'is_virtual' => $this->isVirtual,
            'is_pure_virtual' => $this->isPureVirtual,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $parameters = [];
        foreach (($payload['parameters'] ?? []) as $parameterPayload) {
            if (!is_array($parameterPayload)) {
                continue;
            }

            $parameters[] = OverloadParameter::fromArray($parameterPayload);
        }

        return new self(
            declaringClass: is_string($payload['declaring_class'] ?? null) ? $payload['declaring_class'] : '',
            returnType: is_string($payload['return_type'] ?? null) ? $payload['return_type'] : 'void',
            smartPointerReturnTargetCppType: is_string($payload['smart_pointer_return_target_cpp_type'] ?? null)
                ? $payload['smart_pointer_return_target_cpp_type']
                : null,
            parameters: $parameters,
            access: is_string($payload['access'] ?? null) ? $payload['access'] : 'public',
            isConst: (bool) ($payload['is_const'] ?? false),
            isStatic: (bool) ($payload['is_static'] ?? false),
            isVirtual: (bool) ($payload['is_virtual'] ?? false),
            isPureVirtual: (bool) ($payload['is_pure_virtual'] ?? false),
        );
    }
}
