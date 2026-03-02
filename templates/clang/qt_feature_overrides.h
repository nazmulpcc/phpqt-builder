/*
 * Parser-only Qt feature overrides.
 *
 * QtGui may advertise Vulkan support (QT_FEATURE_vulkan=1) even when the local
 * machine does not provide vulkan/vulkan.h. In that case some headers such as
 * qvulkanwindow.h become uncompilable and break discovery/build workflows.
 *
 * If Vulkan headers are missing, force-disable the feature macro for parsing so
 * those classes are skipped instead of generating wrappers that cannot compile.
 */

#ifndef QT_BUILDER_QT_FEATURE_OVERRIDES_H
#define QT_BUILDER_QT_FEATURE_OVERRIDES_H

#if !__has_include(<vulkan/vulkan.h>) && __has_include(<QtGui/qtgui-config.h>)
#include <QtGui/qtgui-config.h>
#ifdef QT_FEATURE_vulkan
#undef QT_FEATURE_vulkan
#endif
#define QT_FEATURE_vulkan -1
#endif

#endif /* QT_BUILDER_QT_FEATURE_OVERRIDES_H */
