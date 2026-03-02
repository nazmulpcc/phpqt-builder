#pragma once

class QEnumHolder
{
public:
    enum Mode {
        Off = 0,
        On = 1,
    };

    QEnumHolder();
    void setMode(Mode mode);
    Mode mode() const;
};
