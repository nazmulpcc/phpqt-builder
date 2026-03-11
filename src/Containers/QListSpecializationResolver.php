<?php

declare(strict_types=1);

namespace QtBuilder\Containers;

use QtBuilder\CodeGen\ContainerBridge;
use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Parsing\ContainerTypeParser;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppName;

class QListSpecializationResolver
{
    private readonly ContainerTypeParser $parser;
    private readonly CppToPhpTypeMapper $typeMapper;
    private readonly ContainerBridge $containerBridge;

    public function __construct(
        ?ContainerTypeParser $parser = null,
        ?CppToPhpTypeMapper $typeMapper = null,
        ?ContainerBridge $containerBridge = null,
    ) {
        $this->parser = $parser ?? new ContainerTypeParser();
        $this->typeMapper = $typeMapper ?? new CppToPhpTypeMapper();
        $this->containerBridge = $containerBridge ?? new ContainerBridge(typeMapper: $this->typeMapper);
    }

    public function classNameFor(string $cppType): ?string
    {
        return $this->specializationFor($cppType)?->className;
    }

    public function specializationFor(string $cppType): ?QListSpecialization
    {
        $container = $this->parser->parse($cppType);
        if ($container === null || !$container->isSequence()) {
            return null;
        }

        if (!$this->isSupportedListLikeContainer($container->containerName)) {
            return null;
        }

        if (!$this->containerBridge->isSupported($cppType)) {
            return null;
        }

        $normalizedType = $this->normalizeCppType($container->rawType);
        $elementCppType = (string) $container->elementType;
        $elementPhpType = $this->typeMapper->map($elementCppType);

        $className = $this->classNameForContainer($container->containerName, $normalizedType, $elementCppType, $elementPhpType);
        if ($className === null || $className === '') {
            return null;
        }

        return new QListSpecialization(
            className: $className,
            rawType: $normalizedType,
            elementCppType: $elementCppType,
            elementPhpType: $elementPhpType,
            nativeIncludes: $this->nativeIncludesFor($container->containerName, $elementCppType, $elementPhpType),
            nativeAliasOf: $className === $normalizedType ? null : $normalizedType,
        );
    }

    /**
     * @param list<string> $availableClasses
     */
    public function buildPhpClass(QListSpecialization $specialization, array $availableClasses): PhpClass
    {
        $methods = [
            $this->simpleScalarMethod('count', 'int'),
            $this->simpleScalarMethod('size', 'int'),
            $this->simpleScalarMethod('isEmpty', 'bool'),
            $this->simpleVoidMethod('clear'),
        ];

        if ($this->canExposeElementMethods($specialization, $availableClasses)) {
            $methods[] = $this->appendMethod($specialization);
            $methods[] = $this->atMethod($specialization);
        }

        return new PhpClass(
            name: $specialization->className,
            parent: null,
            isAbstract: true,
            isCopyConstructible: true,
            hasPublicConstructor: false,
            hasPublicDestructor: true,
            properties: [],
            methods: $methods,
            signals: [],
            isQObjectDerived: false,
            classConstants: [],
            nativeIncludes: $specialization->nativeIncludes,
            nativeAliasOf: $specialization->nativeAliasOf,
        );
    }

    public function isSyntheticListClassName(string $className): bool
    {
        return $className === 'QStringList'
            || $className === 'QVariantList'
            || $className === 'QModelIndexList'
            || str_starts_with($className, 'QListOf');
    }

    private function isSupportedListLikeContainer(string $containerName): bool
    {
        return in_array($containerName, ['QList', 'QStringList', 'QVariantList', 'QModelIndexList'], true);
    }

    private function classNameForContainer(string $containerName, string $rawType, string $elementCppType, string $elementPhpType): ?string
    {
        if (in_array($rawType, ['QStringList', 'QVariantList', 'QModelIndexList'], true)) {
            return $rawType;
        }

        if ($containerName !== 'QList') {
            return null;
        }

        return 'QListOf' . $this->elementSuffix($elementCppType, $elementPhpType);
    }

    private function elementSuffix(string $elementCppType, string $elementPhpType): string
    {
        return match ($elementPhpType) {
            'int' => 'Int',
            'float' => 'Float',
            'bool' => 'Bool',
            'string' => $this->stringElementSuffix($elementCppType),
            'mixed' => trim($elementCppType) === 'QVariant' ? 'QVariant' : 'Value',
            default => $this->classLikeElementSuffix($elementCppType, $elementPhpType),
        };
    }

    /**
     * @return list<string>
     */
    private function nativeIncludesFor(string $containerName, string $elementCppType, string $elementPhpType): array
    {
        $includes = [];

        if (in_array($containerName, ['QStringList', 'QVariantList', 'QModelIndexList'], true)) {
            $includes[] = sprintf('<%s>', $containerName);
        } else {
            $includes[] = '<QList>';
        }

        $elementInclude = $this->elementNativeInclude($elementCppType, $elementPhpType);
        if ($elementInclude !== null) {
            $includes[] = $elementInclude;
        }

        return array_values(array_unique($includes));
    }

    /**
     * @param list<string> $availableClasses
     */
    private function canExposeElementMethods(QListSpecialization $specialization, array $availableClasses): bool
    {
        $phpType = $specialization->elementPhpType;

        if (in_array($phpType, ['int', 'float', 'bool', 'string'], true)) {
            return true;
        }

        if ($phpType === 'mixed') {
            return trim($specialization->elementCppType) === 'QVariant';
        }

        if (in_array($phpType, $availableClasses, true)) {
            return true;
        }

        $normalizedPhpType = ltrim($phpType, '\\');
        if ($normalizedPhpType !== $phpType && in_array($normalizedPhpType, $availableClasses, true)) {
            return true;
        }

        $cppType = $this->normalizeCppType($specialization->elementCppType);
        if ($cppType !== '' && in_array($cppType, $availableClasses, true)) {
            return true;
        }

        $phpBare = $this->phpClassBaseName($phpType);
        $cppBare = CppName::unqualify($cppType);

        foreach ($availableClasses as $availableClass) {
            $availableBare = CppName::unqualify(str_replace('\\', '::', ltrim($availableClass, '\\')));
            if ($availableBare === '') {
                continue;
            }

            if (($phpBare !== '' && $availableBare === $phpBare) || ($cppBare !== '' && $availableBare === $cppBare)) {
                return true;
            }
        }

        return false;
    }

    private function simpleScalarMethod(string $name, string $phpReturnType): PhpMethod
    {
        $cppReturnType = $phpReturnType === 'bool' ? 'bool' : 'int';

        return new PhpMethod(
            name: $name,
            access: 'public',
            isStatic: false,
            isSignal: false,
            isSlot: false,
            isAbstractMethod: false,
            returnType: $phpReturnType,
            parameters: [],
            overloads: [
                new MethodOverload(
                    declaringClass: '',
                    returnType: $cppReturnType,
                    smartPointerReturnTargetCppType: null,
                    parameters: [],
                    access: 'public',
                    isConst: true,
                    isStatic: false,
                    isVirtual: false,
                    isPureVirtual: false,
                ),
            ],
            cppName: $name,
        );
    }

    private function simpleVoidMethod(string $name): PhpMethod
    {
        return new PhpMethod(
            name: $name,
            access: 'public',
            isStatic: false,
            isSignal: false,
            isSlot: false,
            isAbstractMethod: false,
            returnType: 'void',
            parameters: [],
            overloads: [
                new MethodOverload(
                    declaringClass: '',
                    returnType: 'void',
                    smartPointerReturnTargetCppType: null,
                    parameters: [],
                    access: 'public',
                    isConst: false,
                    isStatic: false,
                    isVirtual: false,
                    isPureVirtual: false,
                ),
            ],
            cppName: $name,
        );
    }

    private function appendMethod(QListSpecialization $specialization): PhpMethod
    {
        $parameter = new PhpParameter(
            name: 'value',
            phpType: $specialization->elementPhpType,
            hasDefault: false,
            position: 0,
        );

        $overloadParameter = new OverloadParameter(
            name: 'value',
            cppType: $specialization->elementCppType,
            hasDefault: false,
            isReference: str_contains($specialization->elementCppType, '&'),
            isConstReference: preg_match('/^\s*const\b/', trim($specialization->elementCppType)) === 1,
            isNonConstReference: str_contains($specialization->elementCppType, '&')
                && preg_match('/^\s*const\b/', trim($specialization->elementCppType)) !== 1,
            isRvalueReference: str_contains($specialization->elementCppType, '&&'),
            pointerDepth: substr_count($specialization->elementCppType, '*'),
        );

        return new PhpMethod(
            name: 'appendItem',
            access: 'public',
            isStatic: false,
            isSignal: false,
            isSlot: false,
            isAbstractMethod: false,
            returnType: 'void',
            parameters: [$parameter],
            overloads: [
                new MethodOverload(
                    declaringClass: '',
                    returnType: 'void',
                    smartPointerReturnTargetCppType: null,
                    parameters: [$overloadParameter],
                    access: 'public',
                    isConst: false,
                    isStatic: false,
                    isVirtual: false,
                    isPureVirtual: false,
                ),
            ],
            cppName: 'append',
        );
    }

    private function atMethod(QListSpecialization $specialization): PhpMethod
    {
        return new PhpMethod(
            name: 'itemAt',
            access: 'public',
            isStatic: false,
            isSignal: false,
            isSlot: false,
            isAbstractMethod: false,
            returnType: $specialization->elementPhpType,
            parameters: [
                new PhpParameter(
                    name: 'index',
                    phpType: 'int',
                    hasDefault: false,
                    position: 0,
                ),
            ],
            overloads: [
                new MethodOverload(
                    declaringClass: '',
                    returnType: $this->atReturnCppType($specialization->elementCppType),
                    smartPointerReturnTargetCppType: null,
                    parameters: [
                        new OverloadParameter(
                            name: 'index',
                            cppType: 'int',
                            hasDefault: false,
                        ),
                    ],
                    access: 'public',
                    isConst: true,
                    isStatic: false,
                    isVirtual: false,
                    isPureVirtual: false,
                ),
            ],
            cppName: 'at',
        );
    }

    private function atReturnCppType(string $elementCppType): string
    {
        if (str_contains($elementCppType, '*')) {
            return $elementCppType;
        }

        return 'const ' . $this->normalizeCppType($elementCppType) . ' &';
    }

    private function elementNativeInclude(string $elementCppType, string $elementPhpType): ?string
    {
        if ($elementPhpType === 'mixed' && trim($elementCppType) === 'QVariant') {
            return '<QVariant>';
        }

        if (in_array($elementPhpType, ['int', 'float', 'bool', 'void', 'array', 'mixed'], true)) {
            return null;
        }

        $base = $this->normalizeCppType($elementCppType);
        if ($base === '') {
            return null;
        }

        if (str_contains($base, '::')) {
            $owner = (string) substr($base, 0, (int) strrpos($base, '::'));
            $ownerBase = CppName::unqualify($owner);
            if ($ownerBase !== '') {
                return sprintf('<%s>', $ownerBase);
            }
        }

        return sprintf('<%s>', $base);
    }

    private function classLikeElementSuffix(string $elementCppType, string $elementPhpType): string
    {
        $phpBase = $this->phpClassBaseName($elementPhpType);
        if ($phpBase !== '') {
            return $this->normalizeIdentifier($phpBase);
        }

        $cppBase = CppName::unqualify($this->normalizeCppType($elementCppType));
        if ($cppBase !== '') {
            return $this->normalizeIdentifier($cppBase);
        }

        return $this->normalizeIdentifier($elementPhpType);
    }

    private function phpClassBaseName(string $phpType): string
    {
        $normalized = ltrim(trim($phpType), '\\');
        if ($normalized === '') {
            return '';
        }

        if (!str_contains($normalized, '\\')) {
            return $normalized;
        }

        return (string) substr($normalized, (int) strrpos($normalized, '\\') + 1);
    }

    private function stringElementSuffix(string $elementCppType): string
    {
        $base = $this->normalizeCppType($elementCppType);

        return match ($base) {
            'QString' => 'QString',
            'QByteArray' => 'QByteArray',
            default => 'String',
        };
    }

    private function normalizeIdentifier(string $type): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($type)) ?? trim($type);
        $parts = array_values(array_filter(
            array_map(static fn(string $part): string => ucfirst($part), explode(' ', $normalized)),
            static fn(string $part): bool => $part !== '',
        ));

        return implode('', $parts);
    }

    private function normalizeCppType(string $type): string
    {
        $type = trim($type);
        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');

        if (!str_contains($type, '<')) {
            while (str_ends_with($type, '*')) {
                $type = rtrim(substr($type, 0, -1));
            }
        }

        return trim($type);
    }
}
