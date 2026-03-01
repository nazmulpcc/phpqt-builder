#pragma once

#include "qnode.h"

class QTree
{
public:
    QTree();
    QNode *root() const;
    void setRoot(QNode *node = nullptr);
};
