#pragma once

class QTransform
{
public:
    QTransform();
    void map(int value) const;
};

inline QTransform::QTransform() = default;
inline void QTransform::map(int value) const
{
    (void) value;
}
