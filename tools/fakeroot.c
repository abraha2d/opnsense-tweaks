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

// LD_PRELOAD shim to redirect hardcoded absolute paths to $FAKE_ROOT prefix
// for integration testing without modifying prod code.
// Intercepts: open, openat, stat, lstat, fstatat, access, unlink, etc.
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

static char *rewrite_path(const char *path) {
    if (!path) return NULL;
    const char *fake_root = getenv("FAKE_ROOT");
    if (!fake_root || fake_root[0] == '\0') return (char *)path;
    for (int i = 0; prefixes[i]; i++) {
        size_t len = strlen(prefixes[i]);
        if (strncmp(path, prefixes[i], len) == 0 && (path[len] == '\0' || path[len] == '/')) {
            static __thread char buf[PATH_MAX];
            snprintf(buf, sizeof(buf), "%s%s", fake_root, path);
            return buf;
        }
    }
    return (char *)path;
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
    int (*real_open)(const char *, int, ...) = dlsym(RTLD_NEXT, "open");
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
    int (*real_open64)(const char *, int, ...) = dlsym(RTLD_NEXT, "open64");
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
    int (*real_openat)(int, const char *, int, ...) = dlsym(RTLD_NEXT, "openat");
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    if (flags & O_CREAT) return real_openat(dirfd, np, flags, mode);
    return real_openat(dirfd, np, flags);
}

int __open_2(const char *pathname, int flags) {
    int (*real)(const char *, int) = dlsym(RTLD_NEXT, "__open_2");
    return real(rewrite_path(pathname), flags);
}

/* stat family */
int stat(const char *pathname, struct stat *buf) {
    int (*real_stat)(const char *, struct stat *) = dlsym(RTLD_NEXT, "stat");
    return real_stat(rewrite_path(pathname), buf);
}

int __xstat(int ver, const char *pathname, struct stat *buf) {
    int (*real)(int, const char *, struct stat *) = dlsym(RTLD_NEXT, "__xstat");
    return real(ver, rewrite_path(pathname), buf);
}

int lstat(const char *pathname, struct stat *buf) {
    int (*real)(const char *, struct stat *) = dlsym(RTLD_NEXT, "lstat");
    return real(rewrite_path(pathname), buf);
}

int __lxstat(int ver, const char *pathname, struct stat *buf) {
    int (*real)(int, const char *, struct stat *) = dlsym(RTLD_NEXT, "__lxstat");
    return real(ver, rewrite_path(pathname), buf);
}

int fstatat(int dirfd, const char *pathname, struct stat *buf, int flags) {
    int (*real)(int, const char *, struct stat *, int) = dlsym(RTLD_NEXT, "fstatat");
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real(dirfd, np, buf, flags);
}

int __fxstatat(int ver, int dirfd, const char *pathname, struct stat *buf, int flags) {
    int (*real)(int, int, const char *, struct stat *, int) = dlsym(RTLD_NEXT, "__fxstatat");
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real(ver, dirfd, np, buf, flags);
}

/* access */
int access(const char *pathname, int mode) {
    int (*real)(const char *, int) = dlsym(RTLD_NEXT, "access");
    return real(rewrite_path(pathname), mode);
}

int euidaccess(const char *pathname, int mode) {
    int (*real)(const char *, int) = dlsym(RTLD_NEXT, "euidaccess");
    return real(rewrite_path(pathname), mode);
}

/* unlink / mkdir / rmdir */
int unlink(const char *pathname) {
    int (*real)(const char *) = dlsym(RTLD_NEXT, "unlink");
    return real(rewrite_path(pathname));
}

int unlinkat(int dirfd, const char *pathname, int flags) {
    int (*real)(int, const char *, int) = dlsym(RTLD_NEXT, "unlinkat");
    const char *np = pathname && pathname[0] == '/' ? rewrite_path(pathname) : pathname;
    return real(dirfd, np, flags);
}

int mkdir(const char *pathname, mode_t mode) {
    int (*real)(const char *, mode_t) = dlsym(RTLD_NEXT, "mkdir");
    return real(rewrite_path(pathname), mode);
}

int rmdir(const char *pathname) {
    int (*real)(const char *) = dlsym(RTLD_NEXT, "rmdir");
    return real(rewrite_path(pathname));
}

/* fopen */
FILE *fopen(const char *pathname, const char *mode) {
    FILE *(*real)(const char *, const char *) = dlsym(RTLD_NEXT, "fopen");
    return real(rewrite_path(pathname), mode);
}

FILE *fopen64(const char *pathname, const char *mode) {
    FILE *(*real)(const char *, const char *) = dlsym(RTLD_NEXT, "fopen64");
    return real(rewrite_path(pathname), mode);
}

/* realpath */
char *realpath(const char *path, char *resolved_path) {
    char *(*real)(const char *, char *) = dlsym(RTLD_NEXT, "realpath");
    // for our prefixes, don't resolve via realpath to avoid following real FS
    for (int i = 0; prefixes[i]; i++) {
        size_t len = strlen(prefixes[i]);
        if (strncmp(path, prefixes[i], len) == 0) {
            const char *fake_root = getenv("FAKE_ROOT");
            if (fake_root && fake_root[0]) {
                static __thread char buf[PATH_MAX];
                snprintf(buf, sizeof(buf), "%s%s", fake_root, path);
                if (resolved_path) {
                    strcpy(resolved_path, buf);
                    return resolved_path;
                } else {
                    return strdup(buf);
                }
            }
        }
    }
    return real(path, resolved_path);
}
