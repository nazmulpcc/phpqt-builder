#pragma once

#include <QtCore/qtmetamacros.h>

class QString;

class QStringSignalFixture
{
    Q_OBJECT

Q_SIGNALS:
    void fileRenamed(const QString &path, const QString &oldName, const QString &newName);
};
