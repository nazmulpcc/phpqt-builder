<?php

declare(strict_types=1);

use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Build\ClassGenerationResult;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Support\ModuleNamespace;

it('widens child visibility for abstract public parent contracts', function (): void {
    $service = new ClassGenerationService();
    $method = new ReflectionMethod($service, 'normalizeMethodsAgainstInheritedContracts');

    $child = new PhpMethod(
        name: 'createShader',
        access: 'protected',
        isStatic: false,
        isSignal: false,
        isSlot: false,
        isAbstractMethod: false,
        returnType: '\\Qt\\Quick\\QSGMaterialShader',
        parameters: [],
        overloads: [],
        cppName: 'createShader',
    );

    $parent = new PhpMethod(
        name: 'createShader',
        access: 'public',
        isStatic: false,
        isSignal: false,
        isSlot: false,
        isAbstractMethod: true,
        returnType: '\\Qt\\Quick\\QSGMaterialShader',
        parameters: [],
        overloads: [],
        cppName: 'createShader',
    );

    /** @var list<PhpMethod> $normalized */
    $normalized = $method->invoke($service, [$child], ['createShader' => $parent]);

    expect($normalized)->toHaveCount(1)
        ->and($normalized[0]->access)->toBe('public')
        ->and($normalized[0]->name)->toBe('createShader');
});

it('resolves qualified prepared parent keys for inherited collision filtering', function (): void {
    $service = new class extends ClassGenerationService {
        public function generateFromPreparedData(
            array $classData,
            string $headerPath,
            array $allowedClasses = [],
            array $preparedClassDataByClass = [],
            bool $preferExternalDependencyReasons = false,
            ?\QtBuilder\Build\EnumHolderRegistry $enumRegistry = null,
        ): ClassGenerationResult {
            $className = (string) ($classData['name'] ?? '');

            $phpClass = match ($className) {
                'QAbstractChannelMapping' => $this->fakeClass('QAbstractChannelMapping', '\\Qt\\Qt3DCore\\QNode', 'Qt3DAnimation::QAbstractChannelMapping'),
                'QNode' => $this->fakeClass('QNode', '\\Qt\\Core\\QObject', 'Qt3DCore::QNode'),
                'QObject' => $this->fakeClass('QObject', null, 'QObject'),
                default => null,
            };

            if (!$phpClass instanceof PhpClass) {
                return ClassGenerationResult::skipped($className, $headerPath, 'missing_fixture', 'Unknown fixture class.');
            }

            return ClassGenerationResult::ok($className, $headerPath, $phpClass);
        }

        private function fakeClass(string $name, ?string $parent, ?string $nativeCppType): PhpClass
        {
            return new PhpClass(
                name: $name,
                parent: $parent,
                isAbstract: false,
                isCopyConstructible: true,
                hasPublicConstructor: true,
                hasPublicDestructor: true,
                properties: [],
                methods: [],
                signals: [],
                nativeCppType: $nativeCppType,
            );
        }
    };

    $childClass = new PhpClass(
        name: 'QChannelMapping',
        parent: '\\Qt\\Qt3DAnimation\\QAbstractChannelMapping',
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [
            new PhpMethod(
                name: 'property',
                access: 'public',
                isStatic: false,
                isSignal: false,
                isSlot: false,
                isAbstractMethod: false,
                returnType: 'string',
                parameters: [],
                overloads: [],
                cppName: 'property',
            ),
            new PhpMethod(
                name: 'setProperty',
                access: 'public',
                isStatic: false,
                isSignal: false,
                isSlot: false,
                isAbstractMethod: false,
                returnType: 'void',
                parameters: [
                    new PhpParameter(name: 'property', phpType: 'string', hasDefault: false, position: 0),
                ],
                overloads: [],
                cppName: 'setProperty',
            ),
        ],
        signals: [],
        nativeCppType: 'Qt3DAnimation::QChannelMapping',
    );

    /** @var array{class: PhpClass, skipped_methods: list<array<string, string>>} $result */
    $invokeFilter = \Closure::bind(
        static function (ClassGenerationService $service, PhpClass $phpClass, array $allowedClasses, array $preparedClassDataByClass): array {
            return $service->filterConflictingInheritedMethodsFromPrepared(
                $phpClass,
                '/tmp/qchannelmapping.h',
                $allowedClasses,
                $preparedClassDataByClass,
            );
        },
        null,
        ClassGenerationService::class,
    );

    $result = $invokeFilter(
        $service,
        $childClass,
        ['Qt3DAnimation::QAbstractChannelMapping', 'Qt3DCore::QNode', 'QObject'],
        [
            'Qt3DAnimation::QAbstractChannelMapping' => [
                'name' => 'QAbstractChannelMapping',
                'qualified_name' => 'Qt3DAnimation::QAbstractChannelMapping',
            ],
            'Qt3DCore::QNode' => [
                'name' => 'QNode',
                'qualified_name' => 'Qt3DCore::QNode',
            ],
            'QObject' => [
                'name' => 'QObject',
                'qualified_name' => 'QObject',
            ],
        ],
    );

    $methodNames = array_map(
        static fn(PhpMethod $method): string => $method->name,
        $result['class']->methods,
    );

    expect($methodNames)->toContain('propertyAsString', 'setPropertyString')
        ->not->toContain('property')
        ->not->toContain('setProperty');
});

it('maps Qt module names to valid PHP namespaces', function (): void {
    expect(ModuleNamespace::forQtModule('QtCore'))->toBe('Qt\\Core')
        ->and(ModuleNamespace::forQtModule('QtQuick3D'))->toBe('Qt\\Quick3D')
        ->and(ModuleNamespace::forQtModule('Qt3DCore'))->toBe('Qt\\Qt3DCore')
        ->and(ModuleNamespace::forQtModule('Qt3DRender'))->toBe('Qt\\Qt3DRender');
});
