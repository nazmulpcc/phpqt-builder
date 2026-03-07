#include <QtOpenGL/qopenglversionfunctions.h>

class QOpenGLFunctions_1_0 : public QAbstractOpenGLFunctions
{
public:
    QOpenGLFunctions_1_0();

    bool hasFeature() const;
};
