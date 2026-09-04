#!/bin/sh
set -e

DIR=/root/opnsense-tweaks

opnsense-code ports tools
( cd /usr/ports/net/dhcpcd && make install )

echo "Copying files..." >&2
cp "$DIR/usr/local/etc/dhcpcd.conf" /usr/local/etc/dhcpcd.conf
cp "$DIR/usr/local/etc/rc.syshook.d/carp/10-wandhcp" /usr/local/etc/rc.syshook.d/carp/10-wandhcp
cp "$DIR/usr/local/libexec/dhcpcd-hooks/10-wancarp" /usr/local/libexec/dhcpcd-hooks/10-wancarp

PEER_IP=$(php "$DIR/src/dhcpcarp-peerinfo.php" peer-ip)
PFSYNC_IP=$(php "$DIR/src/dhcpcarp-peerinfo.php" pfsync-ip)

if [ -n "$PEER_IP" ]; then
  echo "HA peer detected: $PEER_IP"
  echo "$PEER_IP" >"$DIR/.dhcpcarp_peer"

  if [ ! -f /root/.ssh/id_ed25519 ]; then
    echo "Generating SSH key..." >&2
    ssh-keygen -t ed25519 -f /root/.ssh/id_ed25519 -N ''
  fi

  echo "Copying SSH key to peer..."
  ssh-copy-id -i /root/.ssh/id_ed25519.pub root@"$PEER_IP"

  LOCAL_COMMIT=$(git -C "$DIR" rev-parse HEAD 2>/dev/null)
  ORIGIN=$(git -C "$DIR" remote get-url origin 2>/dev/null)

  if [ -n "$LOCAL_COMMIT" ] && [ -n "$ORIGIN" ]; then
    echo "Synchronizing repository to peer (commit $LOCAL_COMMIT)..."
    # shellcheck disable=SC2029
    ssh root@"$PEER_IP" "test -d $DIR/.git || git clone $ORIGIN $DIR"
    # shellcheck disable=SC2029
    ssh root@"$PEER_IP" "git -C $DIR fetch --all --prune && git -C $DIR reset --hard $LOCAL_COMMIT"
    # shellcheck disable=SC2029
    ssh root@"$PEER_IP" "/bin/sh $DIR/install.sh"  # peer has no synchronizetoip; no recursion
  else
    echo "Could not determine local repository state, skipping sync to peer..."
  fi

  if [ -n "$PFSYNC_IP" ]; then
    echo "Synchronizing peer IP to backup firewall..." >&2
    # shellcheck disable=SC2029
    ssh root@"$PEER_IP" "echo $PFSYNC_IP >$DIR/.dhcpcarp_peer"
  fi

else
  echo "No HA peer configured, skipping SSH trust setup..."
fi

echo "All done."
