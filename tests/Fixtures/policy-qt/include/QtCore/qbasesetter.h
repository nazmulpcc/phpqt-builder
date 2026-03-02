#pragma once

class QBaseSetter
{
public:
    QBaseSetter();
    void setPeer(QBaseSetter *peer = nullptr);
    QBaseSetter *peer() const;
};
