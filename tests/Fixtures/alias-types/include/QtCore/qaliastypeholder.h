#pragma once

class QAliasOwner
{
public:
    struct Value {};
};

class QAliasTypeHolder
{
public:
    using Value = QAliasOwner::Value;

    Value value() const;
    void setValue(const Value &value);

    Value storedValue;
};
