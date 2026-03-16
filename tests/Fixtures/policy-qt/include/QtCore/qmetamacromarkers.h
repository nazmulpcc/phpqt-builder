#pragma once

class QObject;

#define Q_GADGET \
public: \
    void qt_check_for_QGADGET_macro();

#define Q_OBJECT \
public: \
    static void qt_static_metacall(QObject *, int, int, void **); \
    virtual void *qt_metacast(const char *); \
    virtual int qt_metacall(int, int, void **);
