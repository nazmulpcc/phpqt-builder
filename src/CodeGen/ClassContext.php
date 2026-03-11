<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Definition\PhpProperty;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

/**
 * Prepares all template variables from a PhpClass IR and a TypeBridge.
 *
 * This is the single object passed into every Blade template. It translates
 * the high-level IR into the concrete C/C++ identifiers, macros, and
 * expressions that templates emit verbatim.
 */
class ClassContext
{
    /** PHP namespace (e.g. "Qt\\Widgets") */
    public readonly string $phpNamespace;

    /** PHP class name (e.g. "QWidget") */
    public readonly string $phpClassName;

    /** Collision-safe internal generation id */
    public readonly string $generationId;

    /** Zend symbol for ZEND_METHOD / ZEND_ME (e.g. "Qt_Widgets_QWidget") */
    public readonly string $zendClassSymbol;

    /** Global zend_class_entry* variable name (e.g. "qt_ce_QWidget") */
    public readonly string $ceVarName;

    /** Global zend_object_handlers variable name (e.g. "qt_qwidget_handlers") */
    public readonly string $handlersVarName;

    /** Custom object struct typedef name (e.g. "qt_qwidget_object") */
    public readonly string $objectStructName;

    /** Inline from_obj() function name (e.g. "qt_qwidget_from_obj") */
    public readonly string $fromObjFunc;

    /** Z_*_P convenience macro (e.g. "Z_QWIDGET_P") */
    public readonly string $zMacro;

    /** C++ type for native_ptr (e.g. "QWidget") */
    public readonly string $nativeCppType;

    /** Constructor owner type for inherited constructor forwarding */
    public readonly string $nativeCtorOwnerType;

    /** Constructor name used in using Base::Base; */
    public readonly string $nativeCtorName;

    /** Whether this is a value type (copyable, no ownership model) */
    public readonly bool $isValueType;

    /** Whether the native class has a usable copy constructor */
    public readonly bool $isCopyConstructible;

    /** Whether generated code may legally delete the native object */
    public readonly bool $hasPublicDestructor;

    /** Whether clone is supported */
    public readonly bool $isCloneable;

    /** Whether the class is final (no subclasses) */
    public readonly bool $isFinal;

    /** Whether the class is abstract */
    public readonly bool $isAbstract;

    /** Parent CE variable name (e.g. "qt_ce_QObject") or null */
    public readonly ?string $parentCeVarName;

    /** Parent class name or null */
    public readonly ?string $parentClassName;

    /** Parent generated file prefix or null */
    public readonly ?string $parentFilePrefix;

    /** Whether the struct has a prevent_destroy field */
    public readonly bool $hasPreventDestroy;

    /** Whether the generated object wrapper needs persistent argv backing storage */
    public readonly bool $needsArgvStorage;

    /** Whether this class inherits QObject and participates in runtime property support */
    public readonly bool $isQObjectDerived;

    /** Whether this class is QObject itself */
    public readonly bool $isQObjectClass;

    /** Whether this class needs an access shim for protected native calls */
    public readonly bool $requiresAccessShim;

    /** Whether this class needs a native trampoline subclass for virtual dispatch */
    public readonly bool $requiresVirtualTrampoline;

    /** Whether constructors allocate a generated native subclass */
    public readonly bool $usesGeneratedNativeSubclass;

    /** Whether wrapper/runtime paths track generated-subclass instances */
    public readonly bool $tracksGeneratedNativeSubclass;

    /** Whether this class exposes at least one constructible native constructor overload */
    public readonly bool $hasConstructibleConstructor;

    /** Generated access shim type name */
    public readonly string $accessShimTypeName;

    /** Generated trampoline type name */
    public readonly string $trampolineTypeName;

    /** Native C++ type used for plain internal-class constructor allocation */
    public readonly string $plainNativeInstantiationType;

    /** Native C++ type used for userland subclass constructor allocation */
    public readonly string $nativeInstantiationType;

    /** Native C++ type used for protected-helper receiver casts */
    public readonly string $protectedCallReceiverType;

    /** Helper struct name for argv-backed application wrappers */
    public readonly ?string $argvStorageStructName;

    /** Qt include directive (e.g. "<QWidget>") */
    public readonly string $qtInclude;

    /** @var list<string> Native Qt includes needed by this wrapper */
    public readonly array $nativeIncludes;

    /** @var list<string> Native Qt includes beyond the primary include */
    public readonly array $extraQtIncludes;

    /** Optional `using` alias that binds the PHP wrapper name to a C++ type */
    public readonly ?string $nativeAliasOf;
    /** @var array<string, string> */
    public readonly array $smartPointerAliases;

    /** MINIT function name (e.g. "qt_qwidget") */
    public readonly string $minitName;

    /** File prefix for generated files (e.g. "qt_qwidget") */
    public readonly string $filePrefix;

    /** Header guard macro (e.g. "QT_QWIDGET_H") */
    public readonly string $headerGuard;

    /** wrap_native() function name (for QObject types) */
    public readonly ?string $wrapNativeFunc;

    /** @var list<MethodContext> */
    public readonly array $methods;

    /** @var list<MethodContext> */
    public readonly array $signals;

    /** @var list<SignalOverloadContext> */
    public readonly array $signalOverloads;

    /** @var list<PropertyContext> */
    public readonly array $properties;

    /** @var list<string> Required #include for cross-class references (e.g. "qt_qpoint.h") */
    public readonly array $requiredIncludes;

    /** @var list<array{name: string, stubType: string, stubValue: string, cInit: string, cTypeMask: string}> */
    public readonly array $classConstants;

    /** The namespace parts for INIT_NS_CLASS_ENTRY (e.g. ["Qt", "Widgets"]) */
    public readonly array $namespaceParts;

    /** INIT_NS_CLASS_ENTRY namespace string (e.g. "Qt\\Widgets") */
    public readonly string $initNsString;

    /** TypeBridge for templates that need dynamic type lookups */
    public readonly TypeBridge $typeBridge;

    /** @var array<string, string> */
    public readonly array $classNamespaces;

    /** @var array<string, array{name: string, namespace: string, generation_id: string, qualified_name: string, module?: string, is_qobject_derived?: bool}> */
    public readonly array $classMetadata;

    public readonly CppClassTypeResolver $classTypeResolver;

    /** Fully-qualified parent class name for stub generation or null */
    public readonly ?string $stubParentClassName;

    /** Arginfo symbol for generated signal connect() */
    public readonly string $signalConnectArginfoName;
    /** Arginfo symbol for generated signal disconnect() */
    public readonly string $signalDisconnectArginfoName;
    /** Arginfo symbol for generated QObject::property() */
    public readonly string $propertyArginfoName;
    /** Arginfo symbol for generated QObject::setProperty() */
    public readonly string $setPropertyArginfoName;
    /** Arginfo symbol for generated QObject::hasProperty() */
    public readonly string $hasPropertyArginfoName;
    /** Arginfo symbol for generated QObject::propertyNames() */
    public readonly string $propertyNamesArginfoName;
    /** Arginfo symbol for generated QObject::propertyInfo() */
    public readonly string $propertyInfoArginfoName;
    /** Arginfo symbol for generated QObject::connectPropertyNotify() */
    public readonly string $connectPropertyNotifyArginfoName;

    public function __construct(
        PhpClass $phpClass,
        string $namespace,
        TypeBridge $typeBridge,
        array $classNamespaces = [],
        array $classNativeTypes = [],
        array $classMetadata = [],
    ) {
        $this->typeBridge = $typeBridge;
        $this->phpNamespace = $namespace;
        $this->classNamespaces = $classNamespaces;
        $this->classMetadata = $classMetadata;
        $this->smartPointerAliases = $phpClass->smartPointerAliases;
        $this->typeBridge->setTypeResolutionMetadata($namespace, $classMetadata, $this->smartPointerAliases, $phpClass->name);
        $this->phpClassName = $phpClass->name;
        $this->generationId = $phpClass->resolvedGenerationId();
        $this->nativeCppType = $phpClass->nativeCppType ?? $phpClass->name;
        $this->nativeCtorOwnerType = $this->nativeCppType;
        $this->nativeCtorName = str_contains($this->nativeCppType, '::')
            ? (string) substr($this->nativeCppType, (int) strrpos($this->nativeCppType, '::') + 2)
            : $phpClass->name;
        $this->isQObjectDerived = $phpClass->isQObjectDerived;
        $this->isQObjectClass = $phpClass->name === 'QObject';

        // Naming
        $this->zendClassSymbol = $typeBridge->zendClassSymbol($namespace, $phpClass->name);
        $this->ceVarName = $typeBridge->ceVarNameForId($this->generationId);
        $this->handlersVarName = $typeBridge->handlersVarNameForId($this->generationId);
        $this->objectStructName = $typeBridge->objectStructNameForId($this->generationId);
        $this->fromObjFunc = $typeBridge->fromObjFuncNameForId($this->generationId);
        $this->zMacro = $typeBridge->zMacroNameForId($this->generationId);
        $this->minitName = $typeBridge->minitNameForId($this->generationId);
        $this->filePrefix = $typeBridge->minitNameForId($this->generationId);
        $this->headerGuard = strtoupper($this->filePrefix) . '_H';
        $nativeIncludes = $phpClass->nativeIncludes !== []
            ? array_values(array_unique($phpClass->nativeIncludes))
            : [$typeBridge->qtInclude($phpClass->name)];
        $this->nativeIncludes = $nativeIncludes;
        $this->qtInclude = $nativeIncludes[0];
        $this->extraQtIncludes = array_slice($nativeIncludes, 1);
        $this->nativeAliasOf = $phpClass->nativeAliasOf;
        $this->classTypeResolver = $this->buildClassTypeResolver($phpClass, $classNativeTypes, $classMetadata);

        // Type classification
        $this->isValueType = $typeBridge->isValueType($phpClass->name);
        $this->isCopyConstructible = $phpClass->isCopyConstructible;
        $this->hasPublicDestructor = $phpClass->hasPublicDestructor;
        $this->isAbstract = $phpClass->isAbstract;
        $this->isFinal = false;
        $this->hasPreventDestroy = !$this->isValueType;

        // Parent
        $this->parentClassName = $phpClass->parent;
        $parentGenerationId = $phpClass->parent !== null
            ? $this->resolveGenerationIdForPhpType($phpClass->parent)
            : null;
        $this->parentCeVarName = $parentGenerationId !== null
            ? $typeBridge->ceVarNameForId($parentGenerationId)
            : null;
        $this->parentFilePrefix = $parentGenerationId !== null
            ? $typeBridge->minitNameForId($parentGenerationId)
            : null;
        $this->stubParentClassName = $phpClass->parent !== null
            ? $typeBridge->stubType($phpClass->parent, false, $namespace, $classNamespaces)
            : null;

        // QObject types get wrap_native
        $this->wrapNativeFunc = !$this->isValueType
            ? $typeBridge->wrapNativeFuncNameForId($this->generationId)
            : null;

        // Namespace for INIT_NS_CLASS_ENTRY
        $this->namespaceParts = explode('\\', $namespace);
        // INIT_NS_CLASS_ENTRY requires C string with escaped backslashes
        $this->initNsString = str_replace('\\', '\\\\', $namespace);

        // Build method contexts
        $methods = [];
        foreach ($phpClass->methods as $method) {
            $methods[] = new MethodContext($method, $this, $typeBridge);
        }
        $this->methods = $methods;
        $this->hasConstructibleConstructor = $phpClass->hasPublicConstructor;
        $this->requiresAccessShim = $this->computeRequiresAccessShim($methods);
        $this->requiresVirtualTrampoline = $this->computeRequiresVirtualTrampoline($methods);
        $this->usesGeneratedNativeSubclass = $this->requiresVirtualTrampoline || $this->computeUsesGeneratedNativeSubclass($methods);
        $this->tracksGeneratedNativeSubclass = $this->usesGeneratedNativeSubclass;
        $this->accessShimTypeName = 'qt_access_' . $phpClass->name;
        $this->trampolineTypeName = 'qt_php_' . $phpClass->name;
        $this->plainNativeInstantiationType = $this->computeUsesGeneratedNativeSubclass($methods)
            ? $this->accessShimTypeName
            : $this->nativeCppType;
        $this->nativeInstantiationType = $this->requiresVirtualTrampoline
            ? $this->trampolineTypeName
            : $this->plainNativeInstantiationType;
        $this->protectedCallReceiverType = $this->requiresAccessShim
            ? $this->accessShimTypeName
            : $this->nativeCppType;
        $this->isCloneable = !$this->usesGeneratedNativeSubclass && $this->isValueType && $this->isCopyConstructible;

        $signals = [];
        foreach ($phpClass->signals as $signal) {
            $signals[] = new MethodContext($signal, $this, $typeBridge);
        }
        $this->signals = $signals;
        $this->signalOverloads = $this->buildSignalOverloads($signals, $typeBridge);
        $this->signalConnectArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'connect',
        );
        $this->signalDisconnectArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'disconnect',
        );
        $this->propertyArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'property',
        );
        $this->setPropertyArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'setProperty',
        );
        $this->hasPropertyArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'hasProperty',
        );
        $this->propertyNamesArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'propertyNames',
        );
        $this->propertyInfoArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'propertyInfo',
        );
        $this->connectPropertyNotifyArginfoName = $typeBridge->arginfoName(
            $this->phpNamespace,
            $this->phpClassName,
            'connectPropertyNotify',
        );
        $this->needsArgvStorage = $this->computeNeedsArgvStorage($methods);
        $this->argvStorageStructName = $this->needsArgvStorage
            ? 'qt_argv_storage'
            : null;

        // Build property contexts
        $properties = [];
        foreach ($phpClass->properties as $property) {
            $properties[] = new PropertyContext($property, $typeBridge);
        }
        $this->properties = $properties;
        $this->classConstants = $this->buildClassConstants($phpClass->classConstants);

        // Compute required cross-class includes
        $this->requiredIncludes = $this->computeRequiredIncludes($phpClass, $typeBridge);
    }

    /**
     * @param array<string, string> $classNativeTypes
     * @param array<string, array{name: string, namespace: string, generation_id: string, qualified_name: string, module?: string, is_qobject_derived?: bool}> $classMetadata
     */
    private function buildClassTypeResolver(PhpClass $phpClass, array $classNativeTypes, array $classMetadata): CppClassTypeResolver
    {
        if ($classMetadata !== []) {
            $classUniverse = [];
            foreach ($classMetadata as $metadata) {
                if (!is_array($metadata)) {
                    continue;
                }

                $className = is_string($metadata['name'] ?? null) ? trim($metadata['name']) : '';
                $qualifiedName = is_string($metadata['qualified_name'] ?? null) ? trim($metadata['qualified_name']) : '';
                $metadataNamespace = is_string($metadata['namespace'] ?? null) ? trim($metadata['namespace']) : '';
                $metadataModule = is_string($metadata['module'] ?? null) ? trim($metadata['module']) : '';
                if ($className === '' || $qualifiedName === '') {
                    continue;
                }

                $classUniverse[] = [
                    'name' => $className,
                    'qualified_name' => $qualifiedName,
                    'module' => $metadataModule !== ''
                        ? $metadataModule
                        : ($this->moduleFromPhpNamespace($metadataNamespace) ?? TypeResolutionContext::moduleForQualifiedName($qualifiedName)),
                ];
            }

            if ($classUniverse !== []) {
                return new CppClassTypeResolver($classUniverse);
            }
        }

        if ($phpClass->nativeCppType !== null && $phpClass->nativeCppType !== '') {
            $classNativeTypes[$phpClass->name] = $phpClass->nativeCppType;
        } elseif (!isset($classNativeTypes[$phpClass->name])) {
            $classNativeTypes[$phpClass->name] = $phpClass->name;
        }

        $classUniverse = [];
        foreach ($classNativeTypes as $className => $qualifiedName) {
            if (!is_string($className) || !is_string($qualifiedName)) {
                continue;
            }

            $className = trim($className);
            $qualifiedName = trim($qualifiedName);
            if ($className === '' || $qualifiedName === '') {
                continue;
            }

            $classUniverse[] = [
                'name' => $className,
                'qualified_name' => $qualifiedName,
                'module' => TypeResolutionContext::moduleForQualifiedName($qualifiedName),
            ];
        }

        return new CppClassTypeResolver($classUniverse);
    }

    public function ceVarNameForPhpType(string $phpType): string
    {
        return $this->typeBridge->ceVarNameForId($this->resolveGenerationIdForPhpType($phpType));
    }

    public function objectStructNameForPhpType(string $phpType): string
    {
        return $this->typeBridge->objectStructNameForId($this->resolveGenerationIdForPhpType($phpType));
    }

    public function fromObjFuncNameForPhpType(string $phpType): string
    {
        return $this->typeBridge->fromObjFuncNameForId($this->resolveGenerationIdForPhpType($phpType));
    }

    public function wrapNativeFuncNameForPhpType(string $phpType): string
    {
        return $this->typeBridge->wrapNativeFuncNameForId($this->resolveGenerationIdForPhpType($phpType));
    }

    public function zMacroNameForPhpType(string $phpType): string
    {
        return $this->typeBridge->zMacroNameForId($this->resolveGenerationIdForPhpType($phpType));
    }

    private function resolveGenerationIdForPhpType(string $phpType): string
    {
        $type = ltrim(trim($phpType), '\\');
        if ($type === '') {
            return $this->generationId;
        }

        $candidate = $this->classMetadata[$type] ?? null;
        if (is_array($candidate) && is_string($candidate['generation_id'] ?? null)) {
            return $candidate['generation_id'];
        }

        $fqcn = str_contains($type, '\\') ? $type : $this->phpNamespace . '\\' . $type;
        foreach ($this->classMetadata as $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $namespace = is_string($metadata['namespace'] ?? null) ? $metadata['namespace'] : '';
            $name = is_string($metadata['name'] ?? null) ? $metadata['name'] : '';
            $generationId = is_string($metadata['generation_id'] ?? null) ? $metadata['generation_id'] : '';
            if ($namespace === '' || $name === '' || $generationId === '') {
                continue;
            }

            if ($fqcn === ltrim($namespace . '\\' . $name, '\\')) {
                return $generationId;
            }
        }

        $uniqueMatches = [];
        foreach ($this->classMetadata as $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $name = is_string($metadata['name'] ?? null) ? $metadata['name'] : '';
            $generationId = is_string($metadata['generation_id'] ?? null) ? $metadata['generation_id'] : '';
            if ($name !== $type || $generationId === '') {
                continue;
            }

            $uniqueMatches[$generationId] = true;
        }

        if (count($uniqueMatches) === 1) {
            return array_key_first($uniqueMatches);
        }

        $resolvedQualified = $this->classTypeResolver->resolveQualifiedClassName(
            $type,
            TypeResolutionContext::fromNames($this->phpClassName, $this->nativeCppType),
        );
        if (is_string($resolvedQualified) && $resolvedQualified !== '') {
            foreach ($this->classMetadata as $metadata) {
                if (!is_array($metadata)) {
                    continue;
                }

                $qualifiedName = is_string($metadata['qualified_name'] ?? null) ? trim($metadata['qualified_name']) : '';
                $generationId = is_string($metadata['generation_id'] ?? null) ? $metadata['generation_id'] : '';
                if ($qualifiedName === '' || $generationId === '') {
                    continue;
                }

                if ($qualifiedName === $resolvedQualified) {
                    return $generationId;
                }
            }
        }

        return $this->typeBridge->generationIdForQualifiedName($type);
    }

    private function moduleFromPhpNamespace(?string $phpNamespace): ?string
    {
        if (!is_string($phpNamespace) || $phpNamespace === '') {
            return null;
        }

        $parts = array_values(array_filter(
            explode('\\', ltrim($phpNamespace, '\\')),
            static fn(string $part): bool => $part !== '',
        ));
        if (count($parts) < 2 || $parts[0] !== 'Qt') {
            return null;
        }

        $suffix = $parts[1];
        if ($suffix === '') {
            return null;
        }

        return str_starts_with($suffix, 'Qt') ? $suffix : ('Qt' . $suffix);
    }

    /**
     * Get methods filtered by access.
     *
     * @return list<MethodContext>
     */
    public function publicMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn(MethodContext $m): bool => $m->access === 'public',
        ));
    }

    /**
     * Get methods filtered by access.
     *
     * @return list<MethodContext>
     */
    public function protectedMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn(MethodContext $m): bool => $m->access === 'protected',
        ));
    }

    /**
     * Get the PHP stub default value expression for an optional parameter.
     *
     * Used in .stub.php generation for gen_stub.php to produce _arginfo.h.
     */
    public function stubDefault(ParamContext $param): string
    {
        if ($param->isNullableByRef) {
            return 'null';
        }

        return match ($param->phpType) {
            'int' => '0',
            'float' => '0.0',
            'bool' => 'false',
            'string' => "''",
            'array' => '[]',
            default => 'null',
        };
    }

    public function stubParamType(ParamContext $param): string
    {
        return $param->stubPhpType;
    }

    /**
     * Scan all methods' parameter types and return types for cross-class
     * references that need #include directives.
     *
     * @return list<string>
     */
    private function computeRequiredIncludes(PhpClass $phpClass, TypeBridge $typeBridge): array
    {
        $classes = [];
        $typeMapper = new CppToPhpTypeMapper();

        foreach ($phpClass->methods as $method) {
            $this->collectClassRefs($method->returnType, $typeBridge, $classes);

            foreach ($method->parameters as $param) {
                $this->collectClassRefs($param->phpType, $typeBridge, $classes);
            }

            foreach ($method->overloads as $overload) {
                $this->collectClassRefs(
                    $this->mapOverloadCppTypeToPhpType($phpClass, $overload->returnType, $typeMapper),
                    $typeBridge,
                    $classes,
                );

                foreach ($typeBridge->containerClassRefs($overload->returnType) as $classRef) {
                    $classes[$classRef] = true;
                }

                foreach ($overload->parameters as $param) {
                    foreach ($typeBridge->containerClassRefs($param->cppType) as $classRef) {
                        $classes[$classRef] = true;
                    }
                }
            }
        }

        foreach ($phpClass->signals as $signal) {
            foreach ($signal->parameters as $param) {
                $this->collectClassRefs($param->phpType, $typeBridge, $classes);
            }

            foreach ($signal->overloads as $overload) {
                foreach ($overload->parameters as $param) {
                    foreach ($typeBridge->containerClassRefs($param->cppType) as $classRef) {
                        $classes[$classRef] = true;
                    }
                }
            }
        }

        // Remove self
        unset($classes[$phpClass->name]);

        // Remove parent (it's included via the header's own include)
        if ($phpClass->parent !== null) {
            unset($classes[$phpClass->parent]);
        }

        $includes = [];
        foreach (array_keys($classes) as $className) {
            $includes[] = $typeBridge->minitNameForId($this->resolveGenerationIdForPhpType($className)) . '.h';
        }

        $includes = array_values(array_unique($includes));
        sort($includes);

        return $includes;
    }

    private function mapOverloadCppTypeToPhpType(PhpClass $phpClass, string $cppType, CppToPhpTypeMapper $typeMapper): string
    {
        return $typeMapper->map(
            $cppType,
            '\\' . ltrim($this->phpNamespace . '\\' . $phpClass->name, '\\'),
            $this->classTypeResolver,
            TypeResolutionContext::fromNames($phpClass->name, $phpClass->nativeCppType ?? $phpClass->name),
            $this->smartPointerAliases,
        );
    }

    /**
     * @param array<string, true> $classes
     */
    private function collectClassRefs(string $phpType, TypeBridge $typeBridge, array &$classes): void
    {
        $parts = explode('|', $phpType);

        foreach ($parts as $part) {
            if ($part === 'QPrivateSignal') {
                continue;
            }
            if ($typeBridge->isObjectType($part)) {
                $classes[$part] = true;
            }
        }
    }

    /**
     * @param list<MethodContext> $methods
     */
    private function computeNeedsArgvStorage(array $methods): bool
    {
        foreach ($methods as $method) {
            foreach ($method->overloads as $overload) {
                foreach ($overload->params as $param) {
                    if ($param->isCharPointerArray) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param list<PhpClassConstant> $classConstants
     * @return list<array{name: string, stubType: string, stubValue: string, cInit: string, cTypeMask: string}>
     */
    private function buildClassConstants(array $classConstants): array
    {
        $result = [];

        foreach ($classConstants as $constant) {
            $stubType = is_int($constant->value)
                ? 'int'
                : (is_float($constant->value) ? 'float' : 'string');

            $cInit = match ($stubType) {
                'int' => sprintf('ZVAL_LONG(&_qt_const_value, (zend_long)(%s));', var_export($constant->value, true)),
                'float' => sprintf('ZVAL_DOUBLE(&_qt_const_value, (double)(%s));', var_export($constant->value, true)),
                default => sprintf(
                    'ZVAL_STRING(&_qt_const_value, "%s");',
                    addcslashes((string) $constant->value, "\\\"\n\r\t\v\f"),
                ),
            };
            $cTypeMask = match ($stubType) {
                'int' => 'MAY_BE_LONG',
                'float' => 'MAY_BE_DOUBLE',
                default => 'MAY_BE_STRING',
            };

            $result[] = [
                'name' => $constant->name,
                'stubType' => $stubType,
                'stubValue' => var_export($constant->value, true),
                'cInit' => $cInit,
                'cTypeMask' => $cTypeMask,
            ];
        }

        return $result;
    }

    public function hasSignals(): bool
    {
        return $this->signalOverloads !== [];
    }

    public function hasClassConstants(): bool
    {
        return $this->classConstants !== [];
    }

    public function hasQObjectPropertySupport(): bool
    {
        return $this->isQObjectDerived;
    }

    public function hasVirtualMethods(): bool
    {
        return $this->requiresVirtualTrampoline;
    }

    public function plainInstantiationUsesGeneratedType(): bool
    {
        return $this->plainNativeInstantiationType !== $this->nativeCppType;
    }

    public function hasPostCallOwnershipHandling(): bool
    {
        foreach ($this->methods as $method) {
            if ($method->postCallLines($this) !== []) {
                return true;
            }

            foreach ($method->overloads as $overload) {
                if ($method->postCallLines($this, $overload) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<MethodContext>
     */
    public function methodsWithCallableProtectedOverloads(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn(MethodContext $method): bool => $method->hasCallableProtectedOverloads,
        ));
    }

    /**
     * @return list<MethodContext>
     */
    public function virtualMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn(MethodContext $method): bool => $method->hasVirtualOverloads,
        ));
    }

    /**
     * @return list<string>
     */
    public function virtualDispatchMethodNames(): array
    {
        $names = [];

        foreach ($this->virtualMethods() as $method) {
            if (!\in_array($method->name, $names, true)) {
                $names[] = $method->name;
            }
        }

        return $names;
    }

    /**
     * @return list<array{method:string, field:string}>
     */
    public function virtualDispatchCacheEntries(): array
    {
        $entries = [];

        foreach ($this->virtualDispatchMethodNames() as $methodName) {
            $entries[] = [
                'method' => $methodName,
                'field' => 'qt_has_override_' . self::sanitizeIdentifierFragment($methodName),
            ];
        }

        return $entries;
    }

    private static function sanitizeIdentifierFragment(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]+/', '_', $name) ?? $name;
        $sanitized = strtolower($sanitized);

        if ($sanitized === '') {
            return 'method';
        }

        if (\ctype_digit($sanitized[0])) {
            return 'm_' . $sanitized;
        }

        return $sanitized;
    }

    /**
     * @param list<MethodContext> $signals
     * @return list<SignalOverloadContext>
     */
    private function buildSignalOverloads(array $signals, TypeBridge $typeBridge): array
    {
        $signalOverloads = [];
        $usedMethodNames = [];

        foreach ($signals as $signal) {
            $baseMethodName = 'on' . ucfirst($signal->name);

            foreach ($signal->overloads as $overload) {
                $callbackParams = $typeBridge->signalCallbackParams($overload->params);
                $phpMethodName = $baseMethodName;
                if (\count($signal->overloads) > 1) {
                    $phpMethodName .= $typeBridge->signalMethodSuffix($callbackParams);
                }

                if (isset($usedMethodNames[$phpMethodName])) {
                    $usedMethodNames[$phpMethodName]++;
                    $phpMethodName .= (string) $usedMethodNames[$phpMethodName];
                } else {
                    $usedMethodNames[$phpMethodName] = 1;
                }

                $signalOverloads[] = new SignalOverloadContext(
                    name: $signal->name,
                    phpMethodName: $phpMethodName,
                    signature: $typeBridge->signalSignature($signal->name, $overload),
                    arginfoName: $typeBridge->arginfoName(
                        $this->phpNamespace,
                        $this->phpClassName,
                        $phpMethodName,
                    ),
                    memberPointerExpr: $typeBridge->signalMemberPointerExpr(
                        $overload->declaringClass !== '' ? $overload->declaringClass : $this->phpClassName,
                        $signal->name,
                        $overload,
                    ),
                    params: $callbackParams,
                );
            }
        }

        return $signalOverloads;
    }

    /**
     * @param list<MethodContext> $methods
     */
    private function computeRequiresAccessShim(array $methods): bool
    {
        foreach ($methods as $method) {
            if ($method->hasCallableProtectedOverloads) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MethodContext> $methods
     */
    private function computeRequiresVirtualTrampoline(array $methods): bool
    {
        if (!$this->hasPublicDestructor) {
            return false;
        }

        if (!$this->isAbstract && !$this->hasConstructibleConstructor) {
            return false;
        }

        foreach ($methods as $method) {
            if ($method->hasVirtualOverloads) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MethodContext> $methods
     */
    private function computeUsesGeneratedNativeSubclass(array $methods): bool
    {
        foreach ($methods as $method) {
            if ($method->hasInstanceProtectedCallPath()) {
                return true;
            }
        }

        return false;
    }
}
