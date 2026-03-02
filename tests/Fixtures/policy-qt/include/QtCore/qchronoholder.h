#include <chrono>

class QChronoHolder
{
public:
    void setInterval(std::chrono::milliseconds value);
    std::chrono::milliseconds interval() const;
};
