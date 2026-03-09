#pragma once

#include <QStringList>

class QContainerWrapperHolder
{
public:
    class Sequence
    {
    public:
        Sequence() = default;
        Sequence(const QStringList &list);
    };

    void setSequence(const Sequence &value);
    Sequence sequence() const;
};
