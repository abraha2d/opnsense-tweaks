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

# Ensure host mount points exist before entering user ns (inside ns host root appears as 65534, sudo broken)
echo "[fake-root] host: uid=$(id -u) stat_root=$(stat -c %u:%U / 2>&1) uid_map=$(cat /proc/self/uid_map 2>&1 | tr '\n' ';') ls_root=$(ls -ld / 2>&1 | head -1); ls_var=$(ls -ld /var /run 2>&1 | tr '\n' ';')" >&2
for d in /var/run/dhcpcd /var/db/dhcpcd /conf /usr/local/etc; do
    if [ ! -d "$d" ]; then
        echo "[fake-root] creating host $d (exists=$(ls -ld $(dirname "$d") 2>&1 | head -1))" >&2
        mkdir -p "$d" 2>&1 || sudo -n mkdir -p "$d" 2>&1 || {
            echo "[fake-root] error: cannot create host mount point $d (need sudo/CAP_DAC_OVERRIDE) uid=$(id -u) stat_parent=$(stat -c %a:%u:%U $(dirname "$d") 2>&1)" >&2
            exit 1
        }
    fi
done
# Handle /var/run -> /run symlink on PHP_IMAGE
if [ -L /var/run ]; then
    if [ ! -d /run/dhcpcd ]; then
        echo "[fake-root] creating /run/dhcpcd for symlink /var/run" >&2
        mkdir -p /run/dhcpcd 2>&1 || sudo -n mkdir -p /run/dhcpcd 2>&1 || echo "[fake-root] warn: cannot create /run/dhcpcd" >&2
    fi
    # ensure /var/run/dhcpcd exists via symlink
    if [ ! -d /var/run/dhcpcd ]; then
        mkdir -p /var/run/dhcpcd 2>&1 || sudo -n mkdir -p /var/run/dhcpcd 2>&1 || echo "[fake-root] warn: cannot create /var/run/dhcpcd" >&2
    fi
fi
if [ ! -d /root/opnsense-tweaks ]; then
    mkdir -p /root/opnsense-tweaks 2>&1 || sudo -n mkdir -p /root/opnsense-tweaks 2>&1 || echo "[fake-root] warn: host /root not writable (550, idmapped), will skip bind" >&2
fi
echo "[fake-root] host mount points ready: $(ls -ld /var/run/dhcpcd /var/db/dhcpcd /conf /usr/local/etc 2>&1 | tr '\n' ';')" >&2

# Execute in private mount namespace with bind mounts (user+mount ns via -r avoids privileged runner)
# shellcheck disable=SC2016
exec unshare -r -m --propagation private -- sh -c '
    FAKE_ROOT="$1"
    shift
    mount --bind "$FAKE_ROOT/var/run/dhcpcd" /var/run/dhcpcd
    mount --bind "$FAKE_ROOT/var/db/dhcpcd" /var/db/dhcpcd
    mount --bind "$FAKE_ROOT/conf" /conf
    mount --bind "$FAKE_ROOT/usr/local/etc" /usr/local/etc
    if [ -d /root/opnsense-tweaks ] && [ -d "$FAKE_ROOT/root/opnsense-tweaks" ]; then
        mount --bind "$FAKE_ROOT/root/opnsense-tweaks" /root/opnsense-tweaks 2>/dev/null || echo "[fake-root] warn: cannot bind /root/opnsense-tweaks" >&2
    fi
    exec "$@"
' sh "$FAKE_ROOT" "$@"
