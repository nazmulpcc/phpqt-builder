#pragma once

class QSvgPoint
{
public:
    QSvgPoint();
    QSvgPoint(int xpos, int ypos);

    bool isNull() const;
    int x() const;
    int y() const;
    void setX(int x);
    void setY(int y);

private:
    int xp;
    int yp;
};

inline QSvgPoint::QSvgPoint() : xp(0), yp(0) {}
inline QSvgPoint::QSvgPoint(int xpos, int ypos) : xp(xpos), yp(ypos) {}
inline bool QSvgPoint::isNull() const { return xp == 0 && yp == 0; }
inline int QSvgPoint::x() const { return xp; }
inline int QSvgPoint::y() const { return yp; }
inline void QSvgPoint::setX(int x) { xp = x; }
inline void QSvgPoint::setY(int y) { yp = y; }
