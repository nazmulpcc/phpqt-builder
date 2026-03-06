#pragma once

class QObject
{
public:
    QObject(QObject *parent = nullptr);
    virtual ~QObject() = default;
};
