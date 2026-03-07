#pragma once

class QEvent
{
public:
    enum Type {
        MouseButtonPress = 2,
        MouseButtonRelease = 3,
        MouseButtonDblClick = 4,
        MouseMove = 5,
        Wheel = 31,
    };

    explicit QEvent(Type type) : type_(type) {}
    virtual ~QEvent() = default;

    Type type() const { return type_; }

private:
    Type type_;
};
