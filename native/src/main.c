/*
 * zstd-extract: safely extracts a .tar.zst archive into a directory.
 *
 * Usage: zstd-extract [--max-size=<bytes>] <archive.tar.zst> <destination>
 *        zstd-extract --version
 *
 * Exit codes: 0 success, 1 invalid or unsafe archive, 2 usage error, 3 I/O error.
 *
 * The extraction rules must stay identical to src/TarExtractor.php, see README.md "Safety rules".
 */

#if defined(_WIN32)
#  define WIN32_LEAN_AND_MEAN
#  ifndef UNICODE
#    define UNICODE
#  endif
#  ifndef _UNICODE
#    define _UNICODE
#  endif
#  include <windows.h>
#  include <io.h>
#  include <fcntl.h>
#  include <sys/stat.h>
#else
#  define _POSIX_C_SOURCE 200809L
#  define _DEFAULT_SOURCE
#  include <dirent.h>
#  include <fcntl.h>
#  include <sys/stat.h>
#  include <sys/types.h>
#  include <unistd.h>
#endif

#include <errno.h>
#include <stdarg.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "zstd.h"

#define EXTRACT_VERSION "1.0.0"
#define WINDOW_LOG_MAX 27
#define MAX_REL 4096
#define MAX_META (1 << 20)
#define MAX_LINK_HOPS 40
#define MAX_MSG_NAME 200

enum { EXIT_OK = 0, EXIT_INVALID = 1, EXIT_USAGE = 2, EXIT_IO = 3 };

#if defined(_MSC_VER)
typedef intptr_t ssize_t;
#endif

static const char *g_dest;
static size_t g_destLen;
static uint64_t g_maxSize = UINT64_MAX; /* limit on the decompressed size */
static char *g_lastParent; /* parent of the previous entry, already verified */

static void fail(int code, const char *fmt, ...)
{
    va_list ap;
    va_start(ap, fmt);
    fputs("zstd-extract: ", stderr);
    vfprintf(stderr, fmt, ap);
    fputc('\n', stderr);
    va_end(ap);
    exit(code);
}

static void *xmalloc(size_t n)
{
    void *p = malloc(n ? n : 1);
    if (!p) fail(EXIT_IO, "out of memory");
    return p;
}

static char *xstrndup(const char *s, size_t n)
{
    char *p = xmalloc(n + 1);
    memcpy(p, s, n);
    p[n] = 0;
    return p;
}

/* archive-controlled text for error messages: bounded (stderr is a pipe) and without control characters */
static char *printable(const char *s)
{
    size_t n = strlen(s), k = n > MAX_MSG_NAME ? MAX_MSG_NAME : n;
    char *p = xmalloc(k + 4);
    for (size_t i = 0; i < k; i++) p[i] = ((unsigned char)s[i] < 0x20 || s[i] == 0x7f) ? '?' : s[i];
    memcpy(p + k, n > k ? "..." : "", n > k ? 4 : 1);
    return p;
}

/* ---------- platform layer ---------- */

#if defined(_WIN32)

#  define IS_WINDOWS 1

/* UTF-8 -> \\?\-prefixed absolute wide path with backslashes */
static wchar_t *wpath(const char *utf8)
{
    int n = MultiByteToWideChar(CP_UTF8, MB_ERR_INVALID_CHARS, utf8, -1, NULL, 0);
    if (n <= 0) fail(EXIT_INVALID, "invalid UTF-8 in path: %s", utf8);
    wchar_t *w = xmalloc(n * sizeof(wchar_t));
    MultiByteToWideChar(CP_UTF8, 0, utf8, -1, w, n);
    for (wchar_t *c = w; *c; c++) if (*c == L'/') *c = L'\\';
    DWORD full = GetFullPathNameW(w, 0, NULL, NULL);
    if (!full) fail(EXIT_IO, "cannot resolve path %s", utf8);
    wchar_t *out = xmalloc((full + 8) * sizeof(wchar_t));
    wchar_t *dst = out;
    if (!(w[0] == L'\\' && w[1] == L'\\')) {
        wcscpy(out, L"\\\\?\\");
        dst = out + 4;
    }
    GetFullPathNameW(w, full, dst, NULL);
    free(w);
    return out;
}

/* 0 missing, 1 file, 2 dir, 3 link/reparse point */
static int ptype(const char *path)
{
    wchar_t *w = wpath(path);
    DWORD a = GetFileAttributesW(w);
    free(w);
    if (a == INVALID_FILE_ATTRIBUTES) return 0;
    if (a & FILE_ATTRIBUTE_REPARSE_POINT) return 3;
    return (a & FILE_ATTRIBUTE_DIRECTORY) ? 2 : 1;
}

static int do_mkdir(const char *path)
{
    wchar_t *w = wpath(path);
    int ok = CreateDirectoryW(w, NULL) || GetLastError() == ERROR_ALREADY_EXISTS;
    free(w);
    return ok ? 0 : -1;
}

static int do_create(const char *path, int exec)
{
    (void)exec;
    wchar_t *w = wpath(path);
    int fd = _wopen(w, _O_WRONLY | _O_CREAT | _O_EXCL | _O_BINARY | _O_NOINHERIT, _S_IREAD | _S_IWRITE);
    free(w);
    return fd;
}

static int do_unlink(const char *path)
{
    wchar_t *w = wpath(path);
    int r = _wunlink(w);
    free(w);
    return r;
}

static int dir_is_empty(const char *path)
{
    size_t n = strlen(path);
    char *pattern = xmalloc(n + 3);
    memcpy(pattern, path, n);
    memcpy(pattern + n, "/*", 3);
    wchar_t *w = wpath(pattern);
    free(pattern);
    WIN32_FIND_DATAW fd;
    HANDLE h = FindFirstFileW(w, &fd);
    free(w);
    if (h == INVALID_HANDLE_VALUE) return 1;
    int empty = 1;
    do {
        if (wcscmp(fd.cFileName, L".") && wcscmp(fd.cFileName, L"..")) { empty = 0; break; }
    } while (FindNextFileW(h, &fd));
    FindClose(h);
    return empty;
}

static FILE *open_archive(const char *path)
{
    wchar_t *w = wpath(path);
    FILE *f = _wfopen(w, L"rb");
    free(w);
    return f;
}

#  define write_fd(fd, buf, n) _write(fd, buf, (unsigned)(n))
#  define close_fd _close

#else

#  define IS_WINDOWS 0

static int ptype(const char *path)
{
    struct stat st;
    if (lstat(path, &st) != 0) return 0;
    if (S_ISLNK(st.st_mode)) return 3;
    if (S_ISDIR(st.st_mode)) return 2;
    return S_ISREG(st.st_mode) ? 1 : 4;
}

static int do_mkdir(const char *path)
{
    return (mkdir(path, 0777) == 0 || errno == EEXIST) ? 0 : -1;
}

static int do_create(const char *path, int exec)
{
    /* O_EXCL never follows a symlink at the final component */
    return open(path, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, exec ? 0777 : 0666);
}

static int do_unlink(const char *path)
{
    return unlink(path);
}

static int dir_is_empty(const char *path)
{
    DIR *d = opendir(path);
    if (!d) return 0;
    struct dirent *e;
    int empty = 1;
    while ((e = readdir(d))) {
        if (strcmp(e->d_name, ".") && strcmp(e->d_name, "..")) { empty = 0; break; }
    }
    closedir(d);
    return empty;
}

static FILE *open_archive(const char *path)
{
    return fopen(path, "rb");
}

static ssize_t write_fd(int fd, const void *buf, size_t n)
{
    size_t done = 0;
    while (done < n) {
        ssize_t w = write(fd, (const char *)buf + done, n - done);
        if (w < 0) {
            if (errno == EINTR) continue;
            return -1;
        }
        done += (size_t)w;
    }
    return (ssize_t)done;
}
#  define close_fd close

#endif

/* ---------- paths ---------- */

/* dest + "/" + rel; caller frees */
static char *full_path(const char *rel)
{
    size_t n = strlen(rel);
    char *p = xmalloc(g_destLen + n + 2);
    memcpy(p, g_dest, g_destLen);
    p[g_destLen] = '/';
    memcpy(p + g_destLen + 1, rel, n + 1);
    return p;
}

static char ascii_upper(char c)
{
    return (c >= 'a' && c <= 'z') ? (char)(c - 32) : c;
}

/* CON, PRN, AUX, NUL, CONIN$, CONOUT$, COM0-9, LPT0-9 (and superscript 1-3), with or without extension */
static int is_reserved_windows_name(const char *c, size_t n)
{
    static const char *names[] = {"CON", "PRN", "AUX", "NUL", "CONIN$", "CONOUT$"};
    size_t base = 0;
    while (base < n && c[base] != '.') base++;
    while (base > 0 && c[base - 1] == ' ') base--;
    char up[8];
    if (base >= sizeof up) return 0;
    for (size_t i = 0; i < base; i++) up[i] = ascii_upper(c[i]);
    up[base] = 0;
    for (size_t i = 0; i < sizeof(names) / sizeof(*names); i++) {
        if (!strcmp(up, names[i])) return 1;
    }
    if (base < 4 || (memcmp(up, "COM", 3) && memcmp(up, "LPT", 3))) return 0;
    if (base == 4) return up[3] >= '0' && up[3] <= '9';
    /* superscript 1, 2, 3 count as digits too (UTF-8) */
    return base == 5 && up[3] == '\xc2' && (up[4] == '\xb9' || up[4] == '\xb2' || up[4] == '\xb3');
}

/*
 * Normalizes an entry name into "a/b/c" form: drops "." and empty components and rejects anything that
 * could escape the destination or misbehave on the target filesystem. Returns NULL with *why set on rejection.
 */
static char *normalize_entry(const char *name, const char **why)
{
    size_t len = strlen(name);
    if (len == 0) { *why = "empty path"; return NULL; }
    if (name[0] == '/') { *why = "absolute path"; return NULL; }
    char *out = xmalloc(len + 1);
    size_t o = 0;
    const char *p = name;
    while (*p) {
        const char *s = p;
        while (*p && *p != '/') p++;
        size_t n = (size_t)(p - s);
        if (*p == '/') p++;
        if (n == 0 || (n == 1 && s[0] == '.')) continue;
        if (n == 2 && s[0] == '.' && s[1] == '.') { *why = "parent directory reference"; free(out); return NULL; }
        for (size_t i = 0; i < n; i++) {
            unsigned char ch = (unsigned char)s[i];
            if (ch < 0x20 || ch == 0x7f || ch == '\\') { *why = "forbidden character"; free(out); return NULL; }
            if (IS_WINDOWS && strchr("<>:\"|?*", ch)) { *why = "character not allowed on Windows"; free(out); return NULL; }
        }
        if (IS_WINDOWS && (s[n - 1] == '.' || s[n - 1] == ' ' || is_reserved_windows_name(s, n))) {
            *why = "name not allowed on Windows";
            free(out);
            return NULL;
        }
        if (o) out[o++] = '/';
        memcpy(out + o, s, n);
        o += n;
    }
    out[o] = 0;
    if (o >= MAX_REL) { *why = "path too long"; free(out); return NULL; }
    return out;
}

static int valid_link_target(const char *t)
{
    if (!*t || t[0] == '/' || strlen(t) >= MAX_REL) return 0;
    for (const unsigned char *c = (const unsigned char *)t; *c; c++) {
        if (*c < 0x20 || *c == 0x7f || *c == '\\') return 0;
    }
    return 1;
}

/* creates every parent directory of rel, refusing to traverse anything that is not a real directory */
static void make_parents(const char *rel)
{
    const char *slash = strrchr(rel, '/');
    if (!slash) return;
    size_t plen = (size_t)(slash - rel);
    if (g_lastParent && strlen(g_lastParent) == plen && !memcmp(g_lastParent, rel, plen)) return;
    char *parent = xstrndup(rel, plen);
    for (size_t i = 0; i <= plen; i++) {
        if (i < plen && parent[i] != '/') continue;
        char c = parent[i];
        parent[i] = 0;
        char *full = full_path(parent);
        int t = ptype(full);
        if (t == 0) {
            if (do_mkdir(full) != 0) fail(EXIT_IO, "cannot create directory %s: %s", parent, strerror(errno));
            t = ptype(full);
        }
        if (t != 2) fail(EXIT_INVALID, "%s exists and is not a directory", parent);
        free(full);
        parent[i] = c;
    }
    free(g_lastParent);
    g_lastParent = parent;
}

/* ---------- decompressing reader ---------- */

typedef struct {
    FILE *f;
    ZSTD_DStream *ds;
    ZSTD_inBuffer in;
    unsigned char *inBuf;
    size_t inCap;
    unsigned char *out;
    size_t outCap, outPos, outLen;
    size_t lastRet;
    uint64_t total;
} reader_t;

/* refills the output buffer, returns 0 at the end of the stream */
static int rd_fill(reader_t *r)
{
    r->outPos = r->outLen = 0;
    while (r->outLen == 0) {
        if (r->in.pos == r->in.size) {
            size_t n = fread(r->inBuf, 1, r->inCap, r->f);
            if (n == 0) {
                if (ferror(r->f)) fail(EXIT_IO, "read error");
                if (r->lastRet != 0) fail(EXIT_INVALID, "truncated zstd stream");
                return 0;
            }
            r->in.src = r->inBuf;
            r->in.size = n;
            r->in.pos = 0;
        }
        ZSTD_outBuffer o = {r->out, r->outCap, 0};
        r->lastRet = ZSTD_decompressStream(r->ds, &o, &r->in);
        if (ZSTD_isError(r->lastRet)) fail(EXIT_INVALID, "zstd: %s", ZSTD_getErrorName(r->lastRet));
        r->outLen = o.pos;
        r->total += o.pos;
        if (r->total > g_maxSize) fail(EXIT_INVALID, "decompressed size exceeds the limit of %llu bytes", (unsigned long long)g_maxSize);
    }
    return 1;
}

static void rd_read(reader_t *r, void *dst, size_t n)
{
    unsigned char *d = dst;
    while (n) {
        if (r->outPos == r->outLen && !rd_fill(r)) fail(EXIT_INVALID, "truncated tar archive");
        size_t k = r->outLen - r->outPos;
        if (k > n) k = n;
        if (d) {
            memcpy(d, r->out + r->outPos, k);
            d += k;
        }
        r->outPos += k;
        n -= k;
    }
}

/* streams n bytes into fd (or skips them when fd < 0) */
static void rd_copy(reader_t *r, int fd, uint64_t n, const char *rel)
{
    while (n) {
        if (r->outPos == r->outLen && !rd_fill(r)) fail(EXIT_INVALID, "truncated tar archive");
        size_t k = r->outLen - r->outPos;
        if (k > n) k = (size_t)n;
        if (fd >= 0 && write_fd(fd, r->out + r->outPos, k) != (ssize_t)k) fail(EXIT_IO, "cannot write %s: %s", rel, strerror(errno));
        r->outPos += k;
        n -= k;
    }
}

/* ---------- tar ---------- */

static int parse_octal(const unsigned char *p, size_t n, uint64_t *v)
{
    size_t i = 0;
    *v = 0;
    if (p[0] & 0x80) return 0; /* base-256: >8GB entries are not supported */
    while (i < n && p[i] == ' ') i++;
    size_t digits = 0;
    for (; i < n && p[i] >= '0' && p[i] <= '7'; i++, digits++) {
        if (*v >> 60) return 0;
        *v = (*v << 3) | (uint64_t)(p[i] - '0');
    }
    for (; i < n; i++) if (p[i] != ' ' && p[i] != 0) return 0;
    return digits > 0;
}

/* pax extended header records: "<len> <key>=<value>\n" */
static void parse_pax(const char *d, size_t n, char **path, char **linkpath)
{
    size_t pos = 0;
    while (pos < n) {
        size_t len = 0, i = pos;
        while (i < n && d[i] >= '0' && d[i] <= '9') {
            if (len > MAX_META) fail(EXIT_INVALID, "malformed pax header"); /* no overflow */
            len = len * 10 + (size_t)(d[i++] - '0');
        }
        if (i >= n || d[i] != ' ' || len == 0 || len > n - pos || d[pos + len - 1] != '\n') fail(EXIT_INVALID, "malformed pax header");
        const char *kv = d + i + 1, *end = d + pos + len - 1;
        const char *eq = memchr(kv, '=', (size_t)(end - kv));
        if (!eq) fail(EXIT_INVALID, "malformed pax header");
        size_t klen = (size_t)(eq - kv), vlen = (size_t)(end - eq - 1);
        if (memchr(eq + 1, 0, vlen)) fail(EXIT_INVALID, "NUL byte in pax value");
        if (klen == 4 && !memcmp(kv, "path", 4)) { free(*path); *path = xstrndup(eq + 1, vlen); }
        else if (klen == 8 && !memcmp(kv, "linkpath", 8)) { free(*linkpath); *linkpath = xstrndup(eq + 1, vlen); }
        else if (klen == 4 && !memcmp(kv, "size", 4)) fail(EXIT_INVALID, "pax size override is not supported");
        pos += len;
    }
}

typedef struct {
    char *rel;
    char *target;
    int created;
} link_t;

static link_t *g_links;
static size_t g_linkCount, g_linkCap;

static void write_file(reader_t *r, const char *rel, uint64_t size, int exec)
{
    make_parents(rel);
    char *full = full_path(rel);
    int fd = do_create(full, exec);
    if (fd < 0 && errno == EEXIST) {
        /* later duplicates win, but only ever replace a regular file */
        if (ptype(full) != 1) fail(EXIT_INVALID, "%s conflicts with an existing entry", rel);
        if (do_unlink(full) != 0) fail(EXIT_IO, "cannot replace %s: %s", rel, strerror(errno));
        fd = do_create(full, exec);
    }
    if (fd < 0) fail(EXIT_IO, "cannot create %s: %s", rel, strerror(errno));
    rd_copy(r, fd, size, rel);
    if (close_fd(fd) != 0) fail(EXIT_IO, "cannot write %s: %s", rel, strerror(errno));
    free(full);
}

static void make_dir(const char *rel)
{
    size_t n = strlen(rel);
    char *child = xmalloc(n + 3);
    memcpy(child, rel, n);
    memcpy(child + n, "/x", 3);
    make_parents(child); /* creates rel itself with the same checks */
    free(child);
}

static char *dirname_of(const char *rel)
{
    const char *s = strrchr(rel, '/');
    return s ? xstrndup(rel, (size_t)(s - rel)) : xstrndup("", 0);
}

static void pop_component(char *cur)
{
    char *s = strrchr(cur, '/');
    if (s) *s = 0; else cur[0] = 0;
}

#if !defined(_WIN32)
/*
 * Walks the link target on the real filesystem, following links like the kernel would, and fails as soon as
 * any step would leave the destination. Missing components are resolved lexically.
 */
static int link_stays_inside(const link_t *l)
{
    char *cur = dirname_of(l->rel);
    size_t remCap = strlen(l->target) + 2;
    char *rem = xmalloc(remCap);
    strcpy(rem, l->target);
    int hops = 0, ok = 1;
    char *p = rem;
    while (*p) {
        char *s = p;
        while (*p && *p != '/') p++;
        size_t n = (size_t)(p - s);
        if (*p == '/') p++;
        if (n == 0 || (n == 1 && s[0] == '.')) continue;
        if (n == 2 && s[0] == '.' && s[1] == '.') {
            if (!cur[0]) { ok = 0; break; }
            pop_component(cur);
            continue;
        }
        size_t cl = strlen(cur);
        char *next = xmalloc(cl + n + 2);
        memcpy(next, cur, cl);
        size_t o = cl;
        if (cl) next[o++] = '/';
        memcpy(next + o, s, n);
        next[o + n] = 0;
        free(cur);
        cur = next;
        if (strlen(cur) >= MAX_REL) { ok = 0; break; }
        char *full = full_path(cur);
        struct stat st;
        if (lstat(full, &st) == 0 && S_ISLNK(st.st_mode)) {
            char buf[MAX_REL];
            ssize_t k = readlink(full, buf, sizeof buf - 1);
            free(full);
            if (k <= 0 || ++hops > MAX_LINK_HOPS) { ok = 0; break; }
            buf[k] = 0;
            if (buf[0] == '/') { ok = 0; break; }
            pop_component(cur);
            /* continue with the link target followed by what was left */
            size_t rl = strlen(p);
            char *nrem = xmalloc((size_t)k + rl + 2);
            memcpy(nrem, buf, (size_t)k);
            nrem[k] = '/';
            memcpy(nrem + k + 1, p, rl + 1);
            free(rem);
            rem = p = nrem;
            continue;
        }
        free(full);
    }
    free(cur);
    free(rem);
    return ok;
}
#endif

static void create_links(void)
{
    /* links now appear on disk, so every parent must be checked again */
    free(g_lastParent);
    g_lastParent = NULL;
    for (size_t i = 0; i < g_linkCount; i++) {
        link_t *l = &g_links[i];
        make_parents(l->rel);
        char *full = full_path(l->rel);
#if defined(_WIN32)
        /* no symlinks on Windows: store the target as file content, like git with core.symlinks=false */
        int fd = do_create(full, 0);
        if (fd < 0 && errno == EEXIST) fail(EXIT_INVALID, "%s conflicts with an existing entry", l->rel);
        if (fd < 0) fail(EXIT_IO, "cannot create %s: %s", l->rel, strerror(errno));
        size_t n = strlen(l->target);
        if (write_fd(fd, l->target, n) != (ssize_t)n || close_fd(fd) != 0) fail(EXIT_IO, "cannot write %s", l->rel);
#else
        if (symlink(l->target, full) != 0) {
            if (errno == EEXIST) fail(EXIT_INVALID, "%s conflicts with an existing entry", l->rel);
            fail(EXIT_IO, "cannot create symlink %s: %s", l->rel, strerror(errno));
        }
#endif
        l->created = 1;
        free(full);
    }
}

static void remove_links(void)
{
    for (size_t i = 0; i < g_linkCount; i++) {
        if (g_links[i].created) {
            char *full = full_path(g_links[i].rel);
            do_unlink(full);
            free(full);
        }
    }
}

static void extract(FILE *f)
{
    reader_t r;
    memset(&r, 0, sizeof r);
    r.f = f;
    r.ds = ZSTD_createDStream();
    if (!r.ds) fail(EXIT_IO, "out of memory");
    ZSTD_DCtx_setParameter(r.ds, ZSTD_d_windowLogMax, WINDOW_LOG_MAX);
    r.inCap = ZSTD_DStreamInSize();
    r.inBuf = xmalloc(r.inCap);
    r.outCap = ZSTD_DStreamOutSize();
    r.out = xmalloc(r.outCap);

    unsigned char h[512];
    char *longName = NULL, *longLink = NULL, *paxPath = NULL, *paxLink = NULL;
    for (;;) {
        rd_read(&r, h, 512);
        int zero = 1;
        for (int i = 0; i < 512 && zero; i++) zero = h[i] == 0;
        if (zero) break;

        uint64_t size, sum, mode;
        if (!parse_octal(h + 148, 8, &sum)) fail(EXIT_INVALID, "invalid tar header");
        uint64_t calc = 0;
        for (int i = 0; i < 512; i++) calc += (i >= 148 && i < 156) ? ' ' : h[i];
        if (calc != sum) fail(EXIT_INVALID, "tar header checksum mismatch");
        if (!parse_octal(h + 124, 12, &size)) fail(EXIT_INVALID, "unsupported tar entry size");
        if (!parse_octal(h + 100, 8, &mode)) mode = 0644;
        char type = (char)h[156];
        uint64_t padded = (size + 511) & ~(uint64_t)511;

        if (type == 'L' || type == 'K' || type == 'x' || type == 'g') {
            if (size > MAX_META) fail(EXIT_INVALID, "metadata entry too large");
            char *d = xmalloc((size_t)size + 1);
            rd_read(&r, d, (size_t)size);
            d[size] = 0;
            rd_read(&r, NULL, (size_t)(padded - size));
            if (type == 'L') { free(longName); longName = xstrndup(d, strlen(d)); }
            else if (type == 'K') { free(longLink); longLink = xstrndup(d, strlen(d)); }
            else if (type == 'x') parse_pax(d, (size_t)size, &paxPath, &paxLink);
            free(d);
            continue;
        }

        char *name;
        if (paxPath) name = xstrndup(paxPath, strlen(paxPath));
        else if (longName) name = xstrndup(longName, strlen(longName));
        else {
            size_t nl = strnlen((const char *)h, 100);
            if (!memcmp(h + 257, "ustar\0", 6) && h[345]) {
                size_t pl = strnlen((const char *)h + 345, 155);
                name = xmalloc(pl + nl + 2);
                memcpy(name, h + 345, pl);
                name[pl] = '/';
                memcpy(name + pl + 1, h, nl);
                name[pl + 1 + nl] = 0;
            } else {
                name = xstrndup((const char *)h, nl);
            }
        }
        char *target = NULL;
        if (type == '2') {
            if (paxLink) target = xstrndup(paxLink, strlen(paxLink));
            else if (longLink) target = xstrndup(longLink, strlen(longLink));
            else target = xstrndup((const char *)h + 157, strnlen((const char *)h + 157, 100));
        }
        free(longName); free(longLink); free(paxPath); free(paxLink);
        longName = longLink = paxPath = paxLink = NULL;

        const char *why = NULL;
        char *rel = normalize_entry(name, &why);
        if (!rel) fail(EXIT_INVALID, "unsafe entry \"%s\": %s", printable(name), why);

        if (type == '0' || type == '\0' || type == '7') {
            if (!rel[0]) fail(EXIT_INVALID, "unsafe entry \"%s\": empty path", printable(name));
            write_file(&r, rel, size, (mode & 0111) != 0);
            rd_read(&r, NULL, (size_t)(padded - size));
            free(rel);
        } else if (type == '5') {
            if (rel[0]) make_dir(rel);
            rd_copy(&r, -1, padded, rel);
            free(rel);
        } else if (type == '2') {
            if (!rel[0]) fail(EXIT_INVALID, "unsafe entry \"%s\": empty path", printable(name));
            if (!valid_link_target(target)) fail(EXIT_INVALID, "unsafe symlink \"%s\" -> \"%s\"", printable(name), printable(target));
            if (g_linkCount == g_linkCap) {
                g_linkCap = g_linkCap ? g_linkCap * 2 : 64;
                g_links = realloc(g_links, g_linkCap * sizeof(link_t));
                if (!g_links) fail(EXIT_IO, "out of memory");
            }
            g_links[g_linkCount].rel = rel;
            g_links[g_linkCount].target = target;
            g_links[g_linkCount].created = 0;
            g_linkCount++;
            target = NULL;
            rd_copy(&r, -1, padded, rel);
        } else {
            fail(EXIT_INVALID, "unsupported entry type '%c' for \"%s\"", type == 0 ? '0' : (type > 0x20 && type < 0x7f) ? type : '?', printable(name));
        }
        free(name);
        free(target);
    }

    /* the whole stream must be valid, which also verifies the checksum of the last frame */
    r.outPos = r.outLen;
    while (rd_fill(&r)) r.outPos = r.outLen;

    /* links last, so no file is ever written through one */
    create_links();
#if !defined(_WIN32)
    for (size_t i = 0; i < g_linkCount; i++) {
        if (!link_stays_inside(&g_links[i])) {
            remove_links();
            fail(EXIT_INVALID, "unsafe symlink \"%s\" -> \"%s\": resolves outside of the destination", g_links[i].rel, g_links[i].target);
        }
    }
#endif
    ZSTD_freeDStream(r.ds);
}

#if defined(_WIN32)
int wmain(int argc, wchar_t **wargv)
{
    char **argv = xmalloc(sizeof(char *) * (size_t)(argc + 1));
    for (int i = 0; i < argc; i++) {
        int n = WideCharToMultiByte(CP_UTF8, 0, wargv[i], -1, NULL, 0, NULL, NULL);
        argv[i] = xmalloc((size_t)n);
        WideCharToMultiByte(CP_UTF8, 0, wargv[i], -1, argv[i], n, NULL, NULL);
    }
    argv[argc] = NULL;
#else
int main(int argc, char **argv)
{
#endif
    if (argc == 2 && !strcmp(argv[1], "--version")) {
        printf("zstd-extract %s (zstd %s)\n", EXTRACT_VERSION, ZSTD_versionString());
        return EXIT_OK;
    }
    if (argc == 4 && !strncmp(argv[1], "--max-size=", 11)) {
        const char *p = argv[1] + 11;
        uint64_t v = 0;
        if (!*p) fail(EXIT_USAGE, "invalid --max-size");
        for (; *p; p++) {
            if (*p < '0' || *p > '9' || v > (UINT64_MAX - (uint64_t)(*p - '0')) / 10) fail(EXIT_USAGE, "invalid --max-size");
            v = v * 10 + (uint64_t)(*p - '0');
        }
        g_maxSize = v;
        argv++;
        argc--;
    }
    if (argc != 3) {
        fputs("Usage: zstd-extract [--max-size=<bytes>] <archive.tar.zst> <destination>\n", stderr);
        return EXIT_USAGE;
    }

    size_t dl = strlen(argv[2]);
    while (dl > 1 && (argv[2][dl - 1] == '/' || (IS_WINDOWS && argv[2][dl - 1] == '\\'))) dl--;
    g_dest = xstrndup(argv[2], dl);
    g_destLen = dl;

    int t = ptype(g_dest);
    if (t == 0) {
        if (do_mkdir(g_dest) != 0) fail(EXIT_IO, "cannot create destination %s: %s", g_dest, strerror(errno));
    } else if (t != 2 || !dir_is_empty(g_dest)) {
        fail(EXIT_USAGE, "destination %s must be an empty directory or not exist", g_dest);
    }

    FILE *f = open_archive(argv[1]);
    if (!f) fail(EXIT_IO, "cannot open %s: %s", argv[1], strerror(errno));
    extract(f);
    fclose(f);

    return EXIT_OK;
}
