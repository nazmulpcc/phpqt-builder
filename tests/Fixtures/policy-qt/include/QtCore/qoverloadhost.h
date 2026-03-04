#include "qsizelike.h"

class QOverloadHost
{
public:
    void resize(const QSizeLike &size);
    void resize(int width, int height);

    void setSlot(int slot, const QSizeLike &size);
    void setSlot(int slot, int mode);
};

inline void QOverloadHost::resize(const QSizeLike &size) { (void) size; }
inline void QOverloadHost::resize(int width, int height) { (void) width; (void) height; }
inline void QOverloadHost::setSlot(int slot, const QSizeLike &size) { (void) slot; (void) size; }
inline void QOverloadHost::setSlot(int slot, int mode) { (void) slot; (void) mode; }
