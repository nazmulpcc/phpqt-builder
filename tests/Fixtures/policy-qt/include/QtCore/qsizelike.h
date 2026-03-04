#include "qcomplexhost.h"

class QSizeLike
{
public:
    QSizeLike();
    QSizeLike(int width, int height);
    QSizeLike(QComplexHost::Iterator representation);

    int width() const;
    int height() const;
};

inline QSizeLike::QSizeLike() {}
inline QSizeLike::QSizeLike(int width, int height) { (void) width; (void) height; }
inline QSizeLike::QSizeLike(QComplexHost::Iterator representation) { (void) representation; }
inline int QSizeLike::width() const { return 0; }
inline int QSizeLike::height() const { return 0; }
