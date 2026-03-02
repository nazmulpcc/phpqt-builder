#include <filesystem>

class QFilesystemPathHolder
{
public:
    std::filesystem::path filesystemPath() const;
    static QFilesystemPathHolder fromFilesystemPath(const std::filesystem::path &path);
};
