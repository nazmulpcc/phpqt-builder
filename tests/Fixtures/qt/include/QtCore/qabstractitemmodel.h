#pragma once

#include "qmodelindex.h"

class QAbstractItemModel
{
public:
    bool hasIndex(int row, int column, const QModelIndex &parent = QModelIndex()) const;
};
