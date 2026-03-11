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
        ->and(ModuleNamespace::forQtModule('Qt3DRender'))->toBe('Qt\\Qt3DRender')
        ->and(ModuleNamespace::forQualifiedCppClass('QtBluetooth', 'QBluetoothServiceInfo::Sequence'))
            ->toBe('Qt\\Bluetooth\\QBluetoothServiceInfo')
        ->and(ModuleNamespace::forQualifiedCppClass('Qt3DInput', 'Qt3DInput::QInputSequence'))
            ->toBe('Qt\\Qt3DInput');
});

it('derives constructor lifecycle and variant flags from cparser runtime metadata', function (): void {
    if (!method_exists(\CParser\MethodCursor::class, 'isDeleted')) {
        test()->markTestSkipped('ext-cparser constructor semantic methods are unavailable.');
    }

    $fixtureDir = qt_temp_dir('qtbuilder-lifecycle-facts-');
    $headerPath = $fixtureDir . '/qctorsemantics.h';

    file_put_contents($headerPath, <<<'CPP'
class QCtorSemantics
{
public:
    QCtorSemantics() = default;
    QCtorSemantics(const QCtorSemantics&) = delete;
    QCtorSemantics(QCtorSemantics&&) = default;

private:
    explicit QCtorSemantics(int);
};
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QCtorSemantics', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $classData = $facts['class_data'];
    expect(is_array($classData))->toBeTrue();
    if (!is_array($classData)) {
        return;
    }

    expect($classData['is_copy_constructible'] ?? null)->toBeFalse()
        ->and($classData['has_public_constructor'] ?? null)->toBeTrue()
        ->and($classData['has_public_default_constructor'] ?? null)->toBeTrue();

    $constructors = array_values(array_filter(
        (array) ($classData['methods'] ?? []),
        static fn(mixed $method): bool => is_array($method) && (($method['name'] ?? null) === 'QCtorSemantics'),
    ));
    expect($constructors)->not->toBe([]);

    $copyCtor = null;
    $moveCtor = null;
    foreach ($constructors as $constructor) {
        $parameters = is_array($constructor['parameters'] ?? null) ? $constructor['parameters'] : [];
        if (count($parameters) !== 1) {
            continue;
        }

        $parameterType = is_string($parameters[0]['type'] ?? null) ? $parameters[0]['type'] : '';
        if (preg_match('/\bconst\s+QCtorSemantics\s*&/', $parameterType) === 1) {
            $copyCtor = $constructor;
            continue;
        }

        if (preg_match('/\bQCtorSemantics\s*&&/', $parameterType) === 1) {
            $moveCtor = $constructor;
        }
    }

    expect($copyCtor)->not->toBeNull()
        ->and((bool) ($copyCtor['is_copy_constructor'] ?? false))->toBeTrue()
        ->and((bool) ($copyCtor['is_deleted'] ?? false))->toBeTrue();

    expect($moveCtor)->not->toBeNull()
        ->and((bool) ($moveCtor['is_move_constructor'] ?? false))->toBeTrue()
        ->and((bool) ($moveCtor['is_defaulted'] ?? false))->toBeTrue();
});

it('extracts class enum names and QFlags aliases from AST metadata', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-flag-alias-facts-');
    $headerPath = $fixtureDir . '/qaliasholder.h';

    file_put_contents($headerPath, <<<'CPP'
template <typename T>
class QFlags
{
public:
    using Int = int;
    static QFlags fromInt(Int value);
};

class QAliasHolder
{
public:
    enum Mode {
        Off = 0,
        On = 1
    };

    using Modes = QFlags<Mode>;
};
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QAliasHolder', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $classData = $facts['class_data'];
    expect(is_array($classData))->toBeTrue();
    if (!is_array($classData)) {
        return;
    }

    expect($classData['enum_names'] ?? null)->toContain('Mode')
        ->and($classData['flag_aliases'] ?? null)->toBe(['Modes' => 'Mode']);
});

it('extracts only referenced nested class facts from the owner parse payload', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-facts-');
    $headerPath = $fixtureDir . '/qnestedowner.h';

    file_put_contents($headerPath, <<<'CPP'
class QNestedOwner
{
public:
    class Used
    {
    public:
        Used() {}
    };

    class Unused
    {
    public:
        Unused() {}
    };

    void setUsed(const QNestedOwner::Used &value);
};

inline void QNestedOwner::setUsed(const QNestedOwner::Used &value) { (void) value; }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedOwner', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $nested = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    $nestedQualified = array_values(array_filter(array_map(
        static fn (mixed $entry): string => is_array($entry) && is_string($entry['qualified_name'] ?? null)
            ? (string) $entry['qualified_name']
            : '',
        $nested,
    )));

    expect($nestedQualified)->toContain('QNestedOwner::Used')
        ->and($nestedQualified)->not->toContain('QNestedOwner::Unused');
});

it('extracts and keeps bare nested template argument classes for QList-based APIs', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-template-facts-');
    $headerPath = $fixtureDir . '/qnestedtemplateowner.h';

    file_put_contents($headerPath, <<<'CPP'
template <typename T>
class QList
{
};

class QNestedTemplateOwner
{
public:
    class AddressInfo
    {
    public:
        AddressInfo() {}
    };

    class Unused
    {
    public:
        Unused() {}
    };

    void setWhiteList(const QList<AddressInfo> &list);
    QList<AddressInfo> whiteList() const;
};

inline void QNestedTemplateOwner::setWhiteList(const QList<AddressInfo> &list) { (void) list; }
inline QList<QNestedTemplateOwner::AddressInfo> QNestedTemplateOwner::whiteList() const { return QList<QNestedTemplateOwner::AddressInfo>(); }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedTemplateOwner', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $ownerClassData = is_array($facts['class_data'] ?? null) ? $facts['class_data'] : [];
    $nestedClassData = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    foreach ([$ownerClassData, ...$nestedClassData] as &$classData) {
        if (!is_array($classData)) {
            continue;
        }
        $classData['module'] = 'QtBluetooth';
    }
    unset($classData);

    $nestedQualified = array_values(array_filter(array_map(
        static fn (mixed $entry): string => is_array($entry) && is_string($entry['qualified_name'] ?? null)
            ? (string) $entry['qualified_name']
            : '',
        $nestedClassData,
    )));
    expect($nestedQualified)->toContain('QNestedTemplateOwner::AddressInfo')
        ->and($nestedQualified)->not->toContain('QNestedTemplateOwner::Unused');

    $prepared = [];
    if (is_array($ownerClassData) && is_string($ownerClassData['qualified_name'] ?? null)) {
        $prepared[(string) $ownerClassData['qualified_name']] = $ownerClassData;
    }
    foreach ($nestedClassData as $entry) {
        if (!is_array($entry) || !is_string($entry['qualified_name'] ?? null)) {
            continue;
        }
        $prepared[(string) $entry['qualified_name']] = $entry;
    }

    $result = $service->generateFromPreparedData(
        $ownerClassData,
        $headerPath,
        ['QNestedTemplateOwner', 'QNestedTemplateOwner::AddressInfo'],
        $prepared,
    );

    expect($result->status)->toBe('ok');
    $methodNames = array_map(
        static fn (\QtBuilder\Definition\PhpMethod $method): string => $method->name,
        $result->phpClass?->methods ?? [],
    );
    expect($methodNames)->toContain('setWhiteList', 'whiteList')
        ->and(array_column($result->skippedMethods, 'reason_message'))->not->toContain(
            'Parameter type const QList<QNestedTemplateOwner::AddressInfo> & is not supported.',
            'Return type QList<QNestedTemplateOwner::AddressInfo> is not supported.',
        );
});

it('skips referenced non-public nested class facts from the owner parse payload', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-non-public-facts-');
    $headerPath = $fixtureDir . '/qnestedaccessowner.h';

    file_put_contents($headerPath, <<<'CPP'
class QNestedAccessOwner
{
public:
    class PublicType
    {
    public:
        PublicType() {}
    };

protected:
    class ProtectedType
    {
    public:
        ProtectedType() {}
    };

public:
    void usePublic(const QNestedAccessOwner::PublicType &value);
    void useProtected(const QNestedAccessOwner::ProtectedType &value);
};

inline void QNestedAccessOwner::usePublic(const QNestedAccessOwner::PublicType &value) { (void) value; }
inline void QNestedAccessOwner::useProtected(const QNestedAccessOwner::ProtectedType &value) { (void) value; }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedAccessOwner', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $nested = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    $nestedQualified = array_values(array_filter(array_map(
        static fn (mixed $entry): string => is_array($entry) && is_string($entry['qualified_name'] ?? null)
            ? (string) $entry['qualified_name']
            : '',
        $nested,
    )));

    expect($nestedQualified)->toContain('QNestedAccessOwner::PublicType')
        ->and($nestedQualified)->not->toContain('QNestedAccessOwner::ProtectedType');
});

it('skips referenced macro-declared nested class facts from non-public sections', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-macro-access-facts-');
    $headerPath = $fixtureDir . '/qnestedmacroowner.h';

    file_put_contents($headerPath, <<<'CPP'
#define DECL_TAG(name) struct name {}
class QNestedMacroOwner
{
private:
    DECL_TAG(HiddenPrivate);
protected:
    DECL_TAG(HiddenProtected);
public:
    class PublicType
    {
    public:
        PublicType() {}
    };

    void usePrivate(const QNestedMacroOwner::HiddenPrivate &value);
    void useProtected(const QNestedMacroOwner::HiddenProtected &value);
    void usePublic(const QNestedMacroOwner::PublicType &value);
};

inline void QNestedMacroOwner::usePrivate(const QNestedMacroOwner::HiddenPrivate &value) { (void) value; }
inline void QNestedMacroOwner::useProtected(const QNestedMacroOwner::HiddenProtected &value) { (void) value; }
inline void QNestedMacroOwner::usePublic(const QNestedMacroOwner::PublicType &value) { (void) value; }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedMacroOwner', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $nested = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    $nestedQualified = array_values(array_filter(array_map(
        static fn (mixed $entry): string => is_array($entry) && is_string($entry['qualified_name'] ?? null)
            ? (string) $entry['qualified_name']
            : '',
        $nested,
    )));

    expect($nestedQualified)->toContain('QNestedMacroOwner::PublicType')
        ->and($nestedQualified)->not->toContain('QNestedMacroOwner::HiddenPrivate')
        ->and($nestedQualified)->not->toContain('QNestedMacroOwner::HiddenProtected');
});

it('skips referenced macro-declared nested class facts when declaration location is in a foreign header', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-foreign-macro-facts-');
    $macroHeaderPath = $fixtureDir . '/qmacro_tags.h';
    $headerPath = $fixtureDir . '/qnestedforeignmacroowner.h';

    file_put_contents($macroHeaderPath, <<<'CPP'
#define DECL_FOREIGN_TAG(name) struct name {}
CPP);

    file_put_contents($headerPath, <<<'CPP'
#include "qmacro_tags.h"

class QNestedForeignMacroOwner
{
private:
    DECL_FOREIGN_TAG(HiddenPrivate);
public:
    class PublicType
    {
    public:
        PublicType() {}
    };

    void usePrivate(const QNestedForeignMacroOwner::HiddenPrivate &value);
    void usePublic(const QNestedForeignMacroOwner::PublicType &value);
};

inline void QNestedForeignMacroOwner::usePrivate(const QNestedForeignMacroOwner::HiddenPrivate &value) { (void) value; }
inline void QNestedForeignMacroOwner::usePublic(const QNestedForeignMacroOwner::PublicType &value) { (void) value; }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedForeignMacroOwner', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $nested = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    $nestedQualified = array_values(array_filter(array_map(
        static fn (mixed $entry): string => is_array($entry) && is_string($entry['qualified_name'] ?? null)
            ? (string) $entry['qualified_name']
            : '',
        $nested,
    )));

    expect($nestedQualified)->toContain('QNestedForeignMacroOwner::PublicType')
        ->and($nestedQualified)->not->toContain('QNestedForeignMacroOwner::HiddenPrivate');
});

it('skips referenced nested class facts whose class name is a php reserved identifier', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-reserved-facts-');
    $headerPath = $fixtureDir . '/qnestedreservedowner.h';

    file_put_contents($headerPath, <<<'CPP'
class QNestedReservedOwner
{
public:
    class NormalType
    {
    public:
        NormalType() {}
    };

    class Private
    {
    public:
        Private() {}
    };

    void useNormal(const QNestedReservedOwner::NormalType &value);
    void useReserved(const QNestedReservedOwner::Private &value);
};

inline void QNestedReservedOwner::useNormal(const QNestedReservedOwner::NormalType &value) { (void) value; }
inline void QNestedReservedOwner::useReserved(const QNestedReservedOwner::Private &value) { (void) value; }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedReservedOwner', [$fixtureDir]);

    expect($facts['status'] ?? null)->toBe('ok');
    $nested = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    $nestedQualified = array_values(array_filter(array_map(
        static fn (mixed $entry): string => is_array($entry) && is_string($entry['qualified_name'] ?? null)
            ? (string) $entry['qualified_name']
            : '',
        $nested,
    )));

    expect($nestedQualified)->toContain('QNestedReservedOwner::NormalType')
        ->and($nestedQualified)->not->toContain('QNestedReservedOwner::Private');
});

it('keeps owner methods that depend on nested classes when nested class data is available', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-generate-');
    $headerPath = $fixtureDir . '/qnestedowner.h';

    file_put_contents($headerPath, <<<'CPP'
class QNestedOwner
{
public:
    class Used
    {
    public:
        Used() {}
    };

    void setUsed(const QNestedOwner::Used &value);
    QNestedOwner::Used used() const;
};

inline void QNestedOwner::setUsed(const QNestedOwner::Used &value) { (void) value; }
inline QNestedOwner::Used QNestedOwner::used() const { return QNestedOwner::Used(); }
CPP);

    $service = new ClassGenerationService();
    $facts = $service->prepareDiscoveryFacts($headerPath, 'QNestedOwner', [$fixtureDir]);
    expect($facts['status'] ?? null)->toBe('ok');

    $ownerClassData = is_array($facts['class_data'] ?? null) ? $facts['class_data'] : [];
    $nestedClassData = is_array($facts['referenced_nested_class_data'] ?? null)
        ? $facts['referenced_nested_class_data']
        : [];
    foreach ([$ownerClassData, ...$nestedClassData] as &$classData) {
        if (!is_array($classData)) {
            continue;
        }
        $classData['module'] = 'QtCore';
    }
    unset($classData);

    $prepared = [];
    if (is_array($ownerClassData) && is_string($ownerClassData['qualified_name'] ?? null)) {
        $prepared[(string) $ownerClassData['qualified_name']] = $ownerClassData;
    }
    foreach ($nestedClassData as $entry) {
        if (!is_array($entry) || !is_string($entry['qualified_name'] ?? null)) {
            continue;
        }
        $prepared[(string) $entry['qualified_name']] = $entry;
    }

    $result = $service->generateFromPreparedData(
        $ownerClassData,
        $headerPath,
        ['QNestedOwner', 'QNestedOwner::Used'],
        $prepared,
    );

    expect($result->status)->toBe('ok');
    $methodNames = array_map(
        static fn (\QtBuilder\Definition\PhpMethod $method): string => $method->name,
        $result->phpClass?->methods ?? [],
    );
    expect($methodNames)->toContain('setUsed', 'used')
        ->and(array_column($result->skippedMethods, 'reason_message'))->not->toContain(
            'Parameter type const QNestedOwner::Used & is not supported.',
            'Return type QNestedOwner::Used is not supported.',
        );
});
