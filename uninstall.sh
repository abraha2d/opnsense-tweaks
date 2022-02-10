killall dhcpcd
( cd /usr/ports/net/dhcpcd && make deinstall )

rm /usr/local/etc/rc.syshook.d/carp/10-wandhcp
rm /usr/local/libexec/dhcpcd-hooks/10-wancarp
