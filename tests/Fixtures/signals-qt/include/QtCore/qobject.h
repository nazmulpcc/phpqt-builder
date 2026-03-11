#pragma once

class QObject
{
public:
    QObject() = default;
    QObject(const QObject &) = delete;
    QObject &operator=(const QObject &) = delete;
    virtual ~QObject() = default;
};
