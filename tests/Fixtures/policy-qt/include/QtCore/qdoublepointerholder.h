#pragma once

class QDoublePointerPeer
{
public:
    int id() const;
};

class QDoublePointerHolder
{
public:
    bool locate(QDoublePointerPeer **peer) const;
    int value() const;
};
