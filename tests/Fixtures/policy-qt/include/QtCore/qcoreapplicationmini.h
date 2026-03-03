#pragma once

class QObject
{
public:
    virtual ~QObject() = default;
};

class QEvent
{
public:
    explicit QEvent(int type);
    virtual ~QEvent() = default;
};

class QCoreApplication : public QObject
{
public:
    QCoreApplication(int argc = 0, char **argv = nullptr, int p2 = 0);

    static bool sendEvent(QObject *receiver, QEvent *event);
    static void postEvent(QObject *receiver, QEvent *event, int priority = 0);
};
