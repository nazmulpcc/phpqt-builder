#pragma once

#include <QtCore/qobject.h>
#include <QtGui/qicon.h>
#include <QtGui/qkeysequence.h>

class QShortcutCarrier : public QObject
{
public:
    QShortcutCarrier(QObject *parent = nullptr);
    QIcon icon() const;
    void setIcon(QIcon icon);
    QKeySequence shortcut() const;
    void setShortcut(QKeySequence shortcut);
};
