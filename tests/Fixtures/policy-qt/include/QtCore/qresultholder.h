class QResultHolder
{
public:
    enum Mode {
        A = 1,
    };

    class Result;

    void setMode(Mode mode);
    static Result decode();
};

class QResultHolder::Result
{
public:
    Result();
};
