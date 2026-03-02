#include <string>

class QStdStringHolder
{
public:
    static QStdStringHolder fromStdString(const std::string &s);
    std::string toStdString() const;
};
