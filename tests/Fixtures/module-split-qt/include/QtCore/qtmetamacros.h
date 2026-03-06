#pragma once

#ifndef QT_ANNOTATE_FUNCTION
#define QT_ANNOTATE_FUNCTION(x)
#endif

#ifndef QT_ANNOTATE_ACCESS_SPECIFIER
#define QT_ANNOTATE_ACCESS_SPECIFIER(x)
#endif

#ifndef Q_SLOTS
#define Q_SLOTS QT_ANNOTATE_ACCESS_SPECIFIER(qt_slot)
#endif

#ifndef Q_SIGNALS
#define Q_SIGNALS public QT_ANNOTATE_ACCESS_SPECIFIER(qt_signal)
#endif

#ifndef Q_SLOT
#define Q_SLOT QT_ANNOTATE_FUNCTION(qt_slot)
#endif

#ifndef Q_SIGNAL
#define Q_SIGNAL QT_ANNOTATE_FUNCTION(qt_signal)
#endif

#ifndef Q_OBJECT
#define Q_OBJECT
#endif

#ifndef QPrivateSignal
class QPrivateSignal {};
#endif
