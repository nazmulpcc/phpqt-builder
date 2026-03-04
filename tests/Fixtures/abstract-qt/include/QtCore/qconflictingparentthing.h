#pragma once

class QObject
{
public:
    QObject *parent() const;
};

class QModelIndex
{
public:
    QModelIndex();
};

class QConflictingParentThing : public QObject
{
public:
    QConflictingParentThing();
    virtual QModelIndex parent(const QModelIndex &index) const = 0;
};
