<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EnumExtractionResult
{
    /**
     * @param list<EnumHolderDefinition> $holders
     */
    public function __construct(
        public string $status,
        public string $headerPath,
        public array $holders = [],
        public ?string $reasonCode = null,
        public ?string $reasonMessage = null,
        public string $stderr = '',
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, string $stderr = ''): self
    {
        $holders = [];
        foreach (($payload['holders'] ?? []) as $holderPayload) {
            if (!is_array($holderPayload)) {
                continue;
            }

            $module = is_string($holderPayload['module'] ?? null) ? $holderPayload['module'] : '';
            $cppType = is_string($holderPayload['cpp_type'] ?? null) ? $holderPayload['cpp_type'] : '';
            $phpNamespace = is_string($holderPayload['php_namespace'] ?? null) ? $holderPayload['php_namespace'] : '';
            $phpClassName = is_string($holderPayload['php_class_name'] ?? null) ? $holderPayload['php_class_name'] : '';
            if ($module === '' || $cppType === '' || $phpNamespace === '' || $phpClassName === '') {
                continue;
            }

            $constants = [];
            foreach (($holderPayload['constants'] ?? []) as $constantPayload) {
                if (!is_array($constantPayload)) {
                    continue;
                }

                $name = is_string($constantPayload['name'] ?? null) ? $constantPayload['name'] : '';
                $value = $constantPayload['value'] ?? null;
                if ($name === '' || (!is_int($value) && !is_float($value) && !is_string($value))) {
                    continue;
                }

                $constants[] = new EnumHolderConstant($name, $value);
            }

            if ($constants === []) {
                continue;
            }

            $holders[] = new EnumHolderDefinition(
                module: $module,
                cppType: $cppType,
                phpNamespace: $phpNamespace,
                phpClassName: $phpClassName,
                constants: $constants,
                isFlagAlias: (bool) ($holderPayload['is_flag_alias'] ?? false),
                sourceCppType: is_string($holderPayload['source_cpp_type'] ?? null) ? $holderPayload['source_cpp_type'] : null,
                headerPath: is_string($holderPayload['header'] ?? null) ? $holderPayload['header'] : '',
            );
        }

        return new self(
            status: (string) ($payload['status'] ?? 'error'),
            headerPath: (string) ($payload['header'] ?? ''),
            holders: $holders,
            reasonCode: isset($payload['reason_code']) ? (string) $payload['reason_code'] : null,
            reasonMessage: isset($payload['reason_message']) ? (string) $payload['reason_message'] : null,
            stderr: $stderr,
        );
    }

    public static function error(string $headerPath, string $reasonMessage, string $stderr = ''): self
    {
        return new self('error', $headerPath, [], 'worker_error', $reasonMessage, $stderr);
    }
}
