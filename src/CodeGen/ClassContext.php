<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Definition\PhpProperty;

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

    /** Whether this is a value type (copyable, no ownership model) */
    public readonly bool $isValueType;

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

    /** Whether the struct has a prevent_destroy field */
    public readonly bool $hasPreventDestroy;

    /** Qt include directive (e.g. "<QWidget>") */
    public readonly string $qtInclude;

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

    /** @var list<PropertyContext> */
    public readonly array $properties;

    /** @var list<string> Required #include for cross-class references (e.g. "qt_qpoint.h") */
    public readonly array $requiredIncludes;

    /** The namespace parts for INIT_NS_CLASS_ENTRY (e.g. ["Qt", "Widgets"]) */
    public readonly array $namespaceParts;

    /** INIT_NS_CLASS_ENTRY namespace string (e.g. "Qt\\Widgets") */
    public readonly string $initNsString;

    /** TypeBridge for templates that need dynamic type lookups */
    public readonly TypeBridge $typeBridge;

    public function __construct(
        PhpClass $phpClass,
        string $namespace,
        TypeBridge $typeBridge,
    ) {
        $this->typeBridge = $typeBridge;
        $this->phpNamespace = $namespace;
        $this->phpClassName = $phpClass->name;
        $this->nativeCppType = $phpClass->name;

        // Naming
        $this->zendClassSymbol = $typeBridge->zendClassSymbol($namespace, $phpClass->name);
        $this->ceVarName = $typeBridge->ceVarName($phpClass->name);
        $this->handlersVarName = $typeBridge->handlersVarName($phpClass->name);
        $this->objectStructName = $typeBridge->objectStructName($phpClass->name);
        $this->fromObjFunc = $typeBridge->fromObjFuncName($phpClass->name);
        $this->zMacro = $typeBridge->zMacroName($phpClass->name);
        $this->minitName = $typeBridge->minitName($phpClass->name);
        $this->filePrefix = $typeBridge->minitName($phpClass->name);
        $this->headerGuard = $typeBridge->classToUpper($phpClass->name) . '_H';
        $this->qtInclude = $typeBridge->qtInclude($phpClass->name);

        // Type classification
        $this->isValueType = $typeBridge->isValueType($phpClass->name);
        $this->isCloneable = $this->isValueType;
        $this->isAbstract = $phpClass->isAbstract;
        $this->isFinal = !$phpClass->isAbstract && $this->isValueType;
        $this->hasPreventDestroy = !$this->isValueType;

        // Parent
        $this->parentClassName = $phpClass->parent;
        $this->parentCeVarName = $phpClass->parent !== null
            ? $typeBridge->ceVarName($phpClass->parent)
            : null;

        // QObject types get wrap_native
        $this->wrapNativeFunc = !$this->isValueType
            ? $typeBridge->wrapNativeFuncName($phpClass->name)
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

        // Build property contexts
        $properties = [];
        foreach ($phpClass->properties as $property) {
            $properties[] = new PropertyContext($property, $typeBridge);
        }
        $this->properties = $properties;

        // Compute required cross-class includes
        $this->requiredIncludes = $this->computeRequiredIncludes($phpClass, $typeBridge);
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
        return match ($param->phpType) {
            'int' => '0',
            'float' => '0.0',
            'bool' => 'false',
            'string' => "''",
            'array' => '[]',
            default => 'null',
        };
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

        foreach ($phpClass->methods as $method) {
            $this->collectClassRefs($method->returnType, $typeBridge, $classes);

            foreach ($method->parameters as $param) {
                $this->collectClassRefs($param->phpType, $typeBridge, $classes);
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
            $includes[] = sprintf('qt_%s.h', $typeBridge->classToLower($className));
        }

        sort($includes);

        return $includes;
    }

    /**
     * @param array<string, true> $classes
     */
    private function collectClassRefs(string $phpType, TypeBridge $typeBridge, array &$classes): void
    {
        $parts = explode('|', $phpType);

        foreach ($parts as $part) {
            if ($typeBridge->isObjectType($part)) {
                $classes[$part] = true;
            }
        }
    }
}
