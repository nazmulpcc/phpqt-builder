#pragma once

class QInheritedEnumBaseThing
{
public:
    enum Mode {
        Alpha = 0,
        Beta = 1,
    };

    virtual ~QInheritedEnumBaseThing() = default;
    virtual Mode currentMode() const { return Alpha; }
    virtual void setMode(Mode mode) { (void) mode; }
};

class QInheritedEnumChildThing : public QInheritedEnumBaseThing
{
public:
    QInheritedEnumChildThing() = default;

    Mode currentMode() const override { return Beta; }
    void setMode(Mode mode) override { (void) mode; }
};
