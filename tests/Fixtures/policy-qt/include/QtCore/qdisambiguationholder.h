namespace Qt {
struct Disambiguated_t {};
inline constexpr Disambiguated_t Disambiguated{};
}

class QDisambiguationHolder
{
public:
    int value() const;
    int count(Qt::Disambiguated_t = Qt::Disambiguated) const;
};
