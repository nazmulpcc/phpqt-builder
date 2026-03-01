#ifndef TEST_QPOINT_H
#define TEST_QPOINT_H

class QPoint
{
public:
    QPoint();
    QPoint(int xpos, int ypos);

    bool isNull() const;
    int x() const;
    int y() const;
    void setX(int x);
    void setY(int y);
    int &rx();

private:
    int xp;
    int yp;
};

inline QPoint::QPoint() : xp(0), yp(0) {}
inline QPoint::QPoint(int xpos, int ypos) : xp(xpos), yp(ypos) {}
inline bool QPoint::isNull() const { return xp == 0 && yp == 0; }
inline int QPoint::x() const { return xp; }
inline int QPoint::y() const { return yp; }
inline void QPoint::setX(int x) { xp = x; }
inline void QPoint::setY(int y) { yp = y; }
inline int &QPoint::rx() { return xp; }

#endif
