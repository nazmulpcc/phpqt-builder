/*
 * Parser-only Qt feature overrides.
 *
 * QtGui may advertise Vulkan support (QT_FEATURE_vulkan=1) even when the local
 * machine does not provide vulkan/vulkan.h. In that case some headers such as
 * qvulkanwindow.h become uncompilable and break discovery/build workflows.
 *
 * Qt's signals/slots are declared through macros that expand to annotations when
 * tooling defines QT_ANNOTATE_ACCESS_SPECIFIER / QT_ANNOTATE_FUNCTION. Wire those
 * macros to clang's annotate attribute so libclang surfaces signal/slot markers
 * for the parser without affecting real extension builds.
 *
 * If Vulkan headers are missing, force-disable the feature macro for parsing so
 * those classes are skipped instead of generating wrappers that cannot compile.
 */

#ifndef QT_BUILDER_QT_FEATURE_OVERRIDES_H
#define QT_BUILDER_QT_FEATURE_OVERRIDES_H

#ifndef QT_ANNOTATE_ACCESS_SPECIFIER
#define QT_ANNOTATE_ACCESS_SPECIFIER(x) __attribute__((annotate(#x)))
#endif

#ifndef QT_ANNOTATE_FUNCTION
#define QT_ANNOTATE_FUNCTION(x) __attribute__((annotate(#x)))
#endif

#if !__has_include(<vulkan/vulkan.h>) && __has_include(<QtGui/qtgui-config.h>)
#include <QtGui/qtgui-config.h>
#ifdef QT_FEATURE_vulkan
#undef QT_FEATURE_vulkan
#endif
#define QT_FEATURE_vulkan -1
#endif

#endif /* QT_BUILDER_QT_FEATURE_OVERRIDES_H */
