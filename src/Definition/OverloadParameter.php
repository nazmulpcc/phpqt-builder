<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * Represents a single C++ overload variant's parameter.
 *
 * Preserves the original C++ type for code generation dispatch.
 */
readonly class OverloadParameter
{
    public function __construct(
        public string $name,
        public string $cppType,
        public bool $hasDefault,
        public ?string $smartPointerTargetCppType = null,
        public bool $isReference = false,
        public bool $isConstReference = false,
        public bool $isNonConstReference = false,
        public bool $isRvalueReference = false,
        public int $pointerDepth = 0,
        public bool $isWritableByRef = false,
        public bool $isWritableByRefPointer = false,
        public bool $isWritableQtString = false,
    ) {}

    /**
     * @return array{name: string, cpp_type: string, has_default: bool, smart_pointer_target_cpp_type: ?string, is_reference: bool, is_const_reference: bool, is_non_const_reference: bool, is_rvalue_reference: bool, pointer_depth: int, is_writable_by_ref: bool, is_writable_by_ref_pointer: bool, is_writable_qt_string: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cpp_type' => $this->cppType,
            'has_default' => $this->hasDefault,
            'smart_pointer_target_cpp_type' => $this->smartPointerTargetCppType,
            'is_reference' => $this->isReference,
            'is_const_reference' => $this->isConstReference,
            'is_non_const_reference' => $this->isNonConstReference,
            'is_rvalue_reference' => $this->isRvalueReference,
            'pointer_depth' => $this->pointerDepth,
            'is_writable_by_ref' => $this->isWritableByRef,
            'is_writable_by_ref_pointer' => $this->isWritableByRefPointer,
            'is_writable_qt_string' => $this->isWritableQtString,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            cppType: is_string($payload['cpp_type'] ?? null) ? $payload['cpp_type'] : '',
            hasDefault: (bool) ($payload['has_default'] ?? false),
            smartPointerTargetCppType: is_string($payload['smart_pointer_target_cpp_type'] ?? null)
                ? $payload['smart_pointer_target_cpp_type']
                : null,
            isReference: (bool) ($payload['is_reference'] ?? false),
            isConstReference: (bool) ($payload['is_const_reference'] ?? false),
            isNonConstReference: (bool) ($payload['is_non_const_reference'] ?? false),
            isRvalueReference: (bool) ($payload['is_rvalue_reference'] ?? false),
            pointerDepth: max(0, (int) ($payload['pointer_depth'] ?? 0)),
            isWritableByRef: (bool) ($payload['is_writable_by_ref'] ?? false),
            isWritableByRefPointer: (bool) ($payload['is_writable_by_ref_pointer'] ?? false),
            isWritableQtString: (bool) ($payload['is_writable_qt_string'] ?? false),
        );
    }
}
