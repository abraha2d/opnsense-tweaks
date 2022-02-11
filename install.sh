opnsense-code ports tools
( cd /usr/ports/net/dhcpcd && make install )

echo "Copying files..."
cp usr/local/etc/dhcpcd.conf /usr/local/etc/dhcpcd.conf
cp usr/local/etc/rc.syshook.d/carp/10-wandhcp /usr/local/etc/rc.syshook.d/carp/10-wandhcp
cp usr/local/libexec/dhcpcd-hooks/10-wancarp /usr/local/libexec/dhcpcd-hooks/10-wancarp
echo "All done."
