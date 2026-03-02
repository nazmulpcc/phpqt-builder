class QArgvHolder
{
public:
    QArgvHolder(int &argc, char **argv, int flags = 0);
    int argcSeen() const;
};
