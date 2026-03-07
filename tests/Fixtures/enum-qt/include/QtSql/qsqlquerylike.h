#pragma once

#include <QtSql/qtsqlglobal.h>

class QSqlQueryLike
{
public:
    QSqlQueryLike();

    void bindValue(int position, int value, QSql::ParamType type);
    QSql::ParamType bindingType() const;
    QSql::TableType tableType() const;
};
