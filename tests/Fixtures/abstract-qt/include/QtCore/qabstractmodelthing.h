#pragma once

class QModelIndex
{
public:
    QModelIndex();
};

class QVariant
{
public:
    QVariant();
};

class QAbstractModelThing
{
public:
    QAbstractModelThing();

    virtual QModelIndex index(int row, int column, const QModelIndex &parent = QModelIndex()) const = 0;
    virtual QModelIndex parent(const QModelIndex &index) const = 0;
    virtual int rowCount(const QModelIndex &parent = QModelIndex()) const = 0;
    virtual int columnCount(const QModelIndex &parent = QModelIndex()) const = 0;
    virtual QVariant data(const QModelIndex &index, int role = 0) const = 0;
};
