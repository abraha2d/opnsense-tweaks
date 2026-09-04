#!/bin/sh
# tools/fake-root.sh - Run a command with fake root bind mounts in a private mount namespace.
# Replaces the former LD_PRELOAD shim (tools/fakeroot.c).
# Requires: unshare(1), mount(8), unprivileged user namespaces (CONFIG_USER_NS) via `unshare -r -m`.
# Usage: FAKE_ROOT=/tmp/fake_root tools/fake-root.sh ./vendor/bin/pest --testsuite=Integration
#     or: tools/fake-root.sh ./vendor/bin/pest --testsuite=Integration  (auto-creates temp root)
set -eu

# Resolve FAKE_ROOT - use env if set, otherwise create temp dir
if [ -z "${FAKE_ROOT:-}" ]; then
    FAKE_ROOT=$(mktemp -d)
    trap 'rm -rf "$FAKE_ROOT"' EXIT INT TERM
fi
export FAKE_ROOT

# Create fake root structure
mkdir -p "$FAKE_ROOT/var/run/dhcpcd" "$FAKE_ROOT/var/db/dhcpcd" "$FAKE_ROOT/conf" "$FAKE_ROOT/usr/local/etc" "$FAKE_ROOT/root/opnsense-tweaks"
if [ ! -f "$FAKE_ROOT/conf/config.xml" ]; then
    echo '<opnsense><hasync><synchronizetoip/></hasync></opnsense>' > "$FAKE_ROOT/conf/config.xml"
fi
if [ ! -f "$FAKE_ROOT/usr/local/etc/config.xml" ]; then
    echo '<opnsense/>' > "$FAKE_ROOT/usr/local/etc/config.xml"
fi

if [ $# -eq 0 ]; then
    echo "FAKE_ROOT=$FAKE_ROOT"
    echo "Usage: $0 <command> [args...]" >&2
    exit 1
fi

# Execute in private mount namespace with bind mounts (user+mount ns via -r avoids privileged runner)
# shellcheck disable=SC2016
exec unshare -r -m --propagation private -- sh -c '
    FAKE_ROOT="$1"
    shift
    mkdir -p /var/run/dhcpcd /var/db/dhcpcd /conf /usr/local/etc 2>/dev/null || true
    mkdir -p /root/opnsense-tweaks 2>/dev/null || echo "[fake-root] warn: cannot mkdir /root/opnsense-tweaks (550, idmapped root)" >&2
    mount --bind "$FAKE_ROOT/var/run/dhcpcd" /var/run/dhcpcd
    mount --bind "$FAKE_ROOT/var/db/dhcpcd" /var/db/dhcpcd
    mount --bind "$FAKE_ROOT/conf" /conf
    mount --bind "$FAKE_ROOT/usr/local/etc" /usr/local/etc
    if [ -d /root/opnsense-tweaks ]; then
        mount --bind "$FAKE_ROOT/root/opnsense-tweaks" /root/opnsense-tweaks 2>/dev/null || echo "[fake-root] warn: cannot bind /root/opnsense-tweaks" >&2
    fi
    exec "$@"
' sh "$FAKE_ROOT" "$@"
