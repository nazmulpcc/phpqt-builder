#include <string>

class QWideStringHolder
{
public:
    static QWideStringHolder fromStdWString(const std::wstring &s);
    std::wstring toStdWString() const;

    static QWideStringHolder fromStdU16String(const std::u16string &s);
    std::u16string toStdU16String() const;

    static QWideStringHolder fromStdU32String(const std::u32string &s);
    std::u32string toStdU32String() const;
};
