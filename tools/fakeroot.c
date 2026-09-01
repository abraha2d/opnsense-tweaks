#define _GNU_SOURCE
#include <dlfcn.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <unistd.h>
#include <limits.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <stdio.h>
#include <dirent.h>
#include <errno.h>

// LD_PRELOAD shim to redirect hardcoded absolute paths to $FAKE_ROOT prefix
// for integration testing without modifying prod code.
// Intercepts: open, openat, stat, lstat, fstatat, access, unlink, mkdir, etc.
// Usage: FAKE_ROOT=/tmp/pest_123 gcc -shared -fPIC -o /tmp/fakeroot.so -ldl tools/fakeroot.c
//        LD_PRELOAD=/tmp/fakeroot.so FAKE_ROOT=/tmp/fake_root pest

static const char *prefixes[] = {
    "/var/run/dhcpcd",
    "/var/db/dhcpcd",
    "/root/opnsense-tweaks",
    "/conf/config.xml",
    "/usr/local/etc/config.xml",
    NULL
};

// Simple canonicalization: collapse //, /./, /../
static void canonicalize(char *path) {
    char *out = path;
    char *p = path;
    char *segment_start = path;
    // Keep leading /
    while (*p) {
        if (p[0] == '/' && p[1] == '/') {
            p++;
            continue;
        }
        if (p[0] == '/' && p[1] == '.' && (p[2] == '/' || p[2] == '\0')) {
            p += 2;
            continue;
        }
        if (p[0] == '/' && p[1] == '.' && p[2] == '.' && (p[3] == '/' || p[3] == '\0')) {
            // backtrack out to previous /
            if (out > path) {
                out--;
                while (out > path && *out != '/') out--;
            }
            p += 3;
            continue;
        }
        *out++ = *p++;
    }
    if (out == path) {
        *out++ = '/';
    }
    *out = '\0';
    // Remove trailing / except root
    size_t len = strlen(path);
    if (len > 1 && path[len - 1] == '/') {
        path[len - 1] = '\0';
    }
    (void)segment_start;
}

static char *rewrite_path(const char *path) {
    if (!path) return NULL;
    const char *fake_root = getenv("FAKE_ROOT");
    if (!fake_root || fake_root[0] == '\0') return (char *)path;
    for (int i = 0; prefixes[i]; i++) {
        size_t len = strlen(prefixes[i]);
        if (strncmp(path, prefixes[i], len) == 0 && (path[len] == '\0' || path[len] == '/')) {
            static __thread char bufs[2][PATH_MAX];
            static __thread int idx = 0;
            char *buf = bufs[idx ^= 1];
            int n = snprintf(buf, sizeof(bufs[0]), "%s%s", fake_root, path);
            if (n < 0 || n >= (int)sizeof(bufs[0])) {
                return (char *)path;
            }
            canonicalize(buf);
            return buf;
        }
    }
    return (char *)path;
}

/* helpers to cache dlsym */
#define RESOLVE(name) \
    static __typeof__(name) *real_##name = NULL; \
    if (!real_##name) { \
        real_##name = (__typeof__(name) *)dlsym(RTLD_NEXT, #name); \
        if (!real_##name) { errno = ENOSYS; return -1; } \
    }

#define RESOLVE_PTR(name) \
    static __typeof__(name) *real_##name = NULL; \
    if (!real_##name) { \
        real_##name = (__typeof__(name) *)dlsym(RTLD_NEXT, #name); \
        if (!real_##name) { errno = ENOSYS; return NULL; } \
    }

/* open */
int open(const char *pathname, int flags, ...) {
    mode_t mode = 0;
    if (flags & O_CREAT) {
        va_list ap;
        va_start(ap, flags);
        mode = va_arg(ap, mode_t);
        va_end(ap);
    }
    RESOLVE(open);
    const char *np = rewrite_path(pathname);
    if (flags & O_CREAT) return real_open(np, flags, mode);
    return real_open(np, flags);
}

int open64(const char *pathname, int flags, ...) {
    mode_t mode = 0;
    if (flags & O_CREAT) {
        va_list ap;
        va_start(ap, flags);
        mode = va_arg(ap, mode_t);
        va_end(ap);
    }
    RESOLVE(open64);
    const char *np = rewrite_path(pathname);
    if (flags & O_CREAT) return real_open64(np, flags, mode);
    return real_open64(np, flags);
}

int openat(int dirfd, const char *pathname, int flags, ...) {
    mode_t mode = 0;
    if (flags & O_CREAT) {
        va_list ap;
        va_start(ap, flags);
        mode = va_arg(ap, mode_t);
        va_end(ap);
    }
    RESOLVE(openat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    if (flags & O_CREAT) return real_openat(dirfd, np, flags, mode);
    return real_openat(dirfd, np, flags);
}

int __open_2(const char *pathname, int flags) {
    RESOLVE(__open_2);
    return real___open_2(rewrite_path(pathname), flags);
}

/* stat family */
int stat(const char *pathname, struct stat *buf) {
    RESOLVE(stat);
    return real_stat(rewrite_path(pathname), buf);
}

int __xstat(int ver, const char *pathname, struct stat *buf) {
    RESOLVE(__xstat);
    return real___xstat(ver, rewrite_path(pathname), buf);
}

int lstat(const char *pathname, struct stat *buf) {
    RESOLVE(lstat);
    return real_lstat(rewrite_path(pathname), buf);
}

int __lxstat(int ver, const char *pathname, struct stat *buf) {
    RESOLVE(__lxstat);
    return real___lxstat(ver, rewrite_path(pathname), buf);
}

int fstatat(int dirfd, const char *pathname, struct stat *buf, int flags) {
    RESOLVE(fstatat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_fstatat(dirfd, np, buf, flags);
}

int __fxstatat(int ver, int dirfd, const char *pathname, struct stat *buf, int flags) {
    RESOLVE(__fxstatat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real___fxstatat(ver, dirfd, np, buf, flags);
}

/* access */
int access(const char *pathname, int mode) {
    RESOLVE(access);
    return real_access(rewrite_path(pathname), mode);
}

int euidaccess(const char *pathname, int mode) {
    RESOLVE(euidaccess);
    return real_euidaccess(rewrite_path(pathname), mode);
}

int faccessat(int dirfd, const char *pathname, int mode, int flags) {
    RESOLVE(faccessat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_faccessat(dirfd, np, mode, flags);
}

/* unlink / mkdir / rmdir / rename / link */
int unlink(const char *pathname) {
    RESOLVE(unlink);
    return real_unlink(rewrite_path(pathname));
}

int unlinkat(int dirfd, const char *pathname, int flags) {
    RESOLVE(unlinkat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_unlinkat(dirfd, np, flags);
}

int mkdir(const char *pathname, mode_t mode) {
    RESOLVE(mkdir);
    return real_mkdir(rewrite_path(pathname), mode);
}

int mkdirat(int dirfd, const char *pathname, mode_t mode) {
    RESOLVE(mkdirat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_mkdirat(dirfd, np, mode);
}

int rmdir(const char *pathname) {
    RESOLVE(rmdir);
    return real_rmdir(rewrite_path(pathname));
}

int rename(const char *oldpath, const char *newpath) {
    RESOLVE(rename);
    const char *a = rewrite_path(oldpath);
    const char *b = rewrite_path(newpath);
    // rewrite_path uses alternating buffers, need to copy first before second overwrites? we already use idx toggle so a and b are distinct
    // but to be safe, copy a to local if needed; second call already uses other buffer, so distinct
    return real_rename(a, b);
}

int renameat(int olddirfd, const char *oldpath, int newdirfd, const char *newpath) {
    RESOLVE(renameat);
    const char *a = oldpath && oldpath[0] == '/' ? rewrite_path(oldpath) : oldpath;
    const char *b = newpath && newpath[0] == '/' ? rewrite_path(newpath) : newpath;
    return real_renameat(olddirfd, a, newdirfd, b);
}

int link(const char *oldpath, const char *newpath) {
    RESOLVE(link);
    return real_link(rewrite_path(oldpath), rewrite_path(newpath));
}

int symlink(const char *target, const char *linkpath) {
    RESOLVE(symlink);
    // target is the symlink contents (may be absolute), linkpath is the path to create
    const char *lp = rewrite_path(linkpath);
    // Only rewrite target if it's an absolute path matching our prefixes? Usually target is not a prefix path, so leave as-is
    // But if target is absolute and matches prefix, rewrite for consistency
    const char *tp = target && target[0] == '/' ? rewrite_path(target) : target;
    (void)tp;
    // For symlink, we want linkpath rewritten, target stays logical (original) to keep fake root internal consistency
    // Use original target to avoid double-prefix; consumers reading link will get original absolute which then rewrites on access
    return real_symlink(target, lp);
}

int symlinkat(const char *target, int newdirfd, const char *linkpath) {
    RESOLVE(symlinkat);
    const char *lp = linkpath && linkpath[0] == '/' ? rewrite_path(linkpath) : linkpath;
    return real_symlinkat(target, newdirfd, lp);
}

/* chmod / chown */
int chmod(const char *pathname, mode_t mode) {
    RESOLVE(chmod);
    return real_chmod(rewrite_path(pathname), mode);
}

int fchmodat(int dirfd, const char *pathname, mode_t mode, int flags) {
    RESOLVE(fchmodat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_fchmodat(dirfd, np, mode, flags);
}

int chown(const char *pathname, uid_t owner, gid_t group) {
    RESOLVE(chown);
    return real_chown(rewrite_path(pathname), owner, group);
}

int lchown(const char *pathname, uid_t owner, gid_t group) {
    RESOLVE(lchown);
    return real_lchown(rewrite_path(pathname), owner, group);
}

int fchownat(int dirfd, const char *pathname, uid_t owner, gid_t group, int flags) {
    RESOLVE(fchownat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_fchownat(dirfd, np, owner, group, flags);
}

/* fopen */
FILE *fopen(const char *pathname, const char *mode) {
    RESOLVE_PTR(fopen);
    return real_fopen(rewrite_path(pathname), mode);
}

FILE *fopen64(const char *pathname, const char *mode) {
    RESOLVE_PTR(fopen64);
    return real_fopen64(rewrite_path(pathname), mode);
}

/* opendir / readlink */
DIR *opendir(const char *name) {
    RESOLVE_PTR(opendir);
    return real_opendir(rewrite_path(name));
}

ssize_t readlink(const char *pathname, char *buf, size_t bufsiz) {
    RESOLVE(readlink);
    return real_readlink(rewrite_path(pathname), buf, bufsiz);
}

ssize_t readlinkat(int dirfd, const char *pathname, char *buf, size_t bufsiz) {
    RESOLVE(readlinkat);
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real_readlinkat(dirfd, np, buf, bufsiz);
}

/* realpath */
char *realpath(const char *path, char *resolved_path) {
    RESOLVE_PTR(realpath);
    for (int i = 0; prefixes[i]; i++) {
        size_t len = strlen(prefixes[i]);
        if (strncmp(path, prefixes[i], len) == 0) {
            const char *fake_root = getenv("FAKE_ROOT");
            if (fake_root && fake_root[0]) {
                static __thread char bufs[2][PATH_MAX];
                static __thread int idx = 0;
                char *buf = bufs[idx ^= 1];
                int n = snprintf(buf, sizeof(bufs[0]), "%s%s", fake_root, path);
                if (n < 0 || n >= (int)sizeof(bufs[0])) {
                    return real_realpath(path, resolved_path);
                }
                canonicalize(buf);
                if (resolved_path) {
                    strcpy(resolved_path, buf);
                    return resolved_path;
                } else {
                    return strdup(buf);
                }
            }
        }
    }
    return real_realpath(path, resolved_path);
}
