#pragma once

class QString;

class QStringPointerHolder
{
public:
    static QString pickLabel(const QString &fallback = QString(), QString *selectedFilter = nullptr);
};
