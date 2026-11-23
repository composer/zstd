//! zstd-extract: safely extracts a .tar.zst archive into a directory.
//!
//! Usage: zstd-extract [--max-size=<bytes>] <archive.tar.zst> <destination>
//!        zstd-extract --version
//!
//! Exit codes: 0 success, 1 invalid or unsafe archive, 2 usage error, 3 I/O error.
//!
//! The extraction rules must stay identical to src/TarExtractor.php, see README.md "Safety rules".

// unsafe code only in the FFI modules
#![deny(unsafe_code)]

use std::fs::{self, File, OpenOptions};
use std::io::{self, Read, Write};
use std::path::{Path, PathBuf};
use std::process::exit;

#[cfg(target_os = "linux")]
#[allow(unsafe_code)]
mod relro;
#[allow(unsafe_code)]
mod zstd;

const EXTRACT_VERSION: &str = env!("CARGO_PKG_VERSION");
const WINDOW_LOG_MAX: i32 = 27;
const MAX_REL: usize = 4096;
const MAX_META: u64 = 1 << 20;
#[cfg(unix)]
const MAX_LINK_HOPS: u32 = 40;
#[cfg(unix)]
const MAX_LINK_STEPS: u64 = 1_000_000;
const MAX_MSG_NAME: usize = 200;

const EXIT_INVALID: i32 = 1;
const EXIT_USAGE: i32 = 2;
const EXIT_IO: i32 = 3;

pub fn fail(code: i32, msg: impl AsRef<str>) -> ! {
    eprintln!("zstd-extract: {}", msg.as_ref());
    exit(code);
}

/// like strerror(), without Rust's " (os error N)" suffix
fn err_str(e: &io::Error) -> String {
    let s = e.to_string();
    match s.find(" (os error ") {
        Some(i) => s[..i].to_string(),
        None => s,
    }
}

/// archive-controlled text for error messages: bounded and without control characters
fn printable(s: &[u8]) -> String {
    let k = s.len().min(MAX_MSG_NAME);
    let cleaned: Vec<u8> = s[..k]
        .iter()
        .map(|&c| if c < 0x20 || c == 0x7f { b'?' } else { c })
        .collect();
    let mut out = String::from_utf8_lossy(&cleaned).into_owned();
    if s.len() > k {
        out.push_str("...");
    }
    out
}

fn lossy(s: &[u8]) -> String {
    String::from_utf8_lossy(s).into_owned()
}

#[derive(PartialEq, Clone, Copy)]
enum PType {
    Missing,
    File,
    Dir,
    Link,
    Other,
}

fn ptype(path: &Path) -> PType {
    match fs::symlink_metadata(path) {
        Err(_) => PType::Missing,
        Ok(m) => {
            let t = m.file_type();
            if is_link(&m) {
                PType::Link
            } else if t.is_dir() {
                PType::Dir
            } else if t.is_file() {
                PType::File
            } else {
                PType::Other
            }
        }
    }
}

#[cfg(unix)]
fn is_link(m: &fs::Metadata) -> bool {
    m.file_type().is_symlink()
}

/// any reparse point, junctions too
#[cfg(windows)]
fn is_link(m: &fs::Metadata) -> bool {
    use std::os::windows::fs::MetadataExt;
    m.file_attributes() & 0x400 != 0 // FILE_ATTRIBUTE_REPARSE_POINT
}

/* ---------- decompressing reader ---------- */

struct Reader {
    f: File,
    ds: zstd::Stream,
    input: Vec<u8>,
    in_pos: usize,
    in_len: usize,
    last_ret: usize,
    out: Vec<u8>,
    out_len: usize,
    pos: usize,
    total: u64,
    max_size: u64,
}

impl Reader {
    fn new(f: File, max_size: u64) -> Reader {
        let ds =
            zstd::Stream::new(WINDOW_LOG_MAX).unwrap_or_else(|| fail(EXIT_IO, "out of memory"));
        let (in_size, out_size) = (zstd::in_size(), zstd::out_size());
        Reader {
            f,
            ds,
            input: vec![0; in_size],
            in_pos: 0,
            in_len: 0,
            last_ret: 0,
            out: vec![0; out_size],
            out_len: 0,
            pos: 0,
            total: 0,
            max_size,
        }
    }

    /// refills the output buffer, returns false at the end of the stream
    fn fill(&mut self) -> bool {
        self.pos = 0;
        self.out_len = 0;
        while self.out_len == 0 {
            if self.in_pos == self.in_len {
                let n = read_some(&mut self.f, &mut self.input);
                if n == 0 {
                    if self.last_ret != 0 {
                        fail(EXIT_INVALID, "truncated zstd stream");
                    }
                    return false;
                }
                self.in_pos = 0;
                self.in_len = n;
            }
            let (n, ret) = self
                .ds
                .decompress(&self.input[..self.in_len], &mut self.in_pos, &mut self.out)
                .unwrap_or_else(|e| fail(EXIT_INVALID, format!("zstd: {e}")));
            self.last_ret = ret;
            self.out_len = n;
            self.total += n as u64;
            if self.total > self.max_size {
                fail(
                    EXIT_INVALID,
                    format!(
                        "decompressed size exceeds the limit of {} bytes",
                        self.max_size
                    ),
                );
            }
        }
        true
    }

    fn available(&mut self) -> &[u8] {
        if self.pos == self.out_len && !self.fill() {
            fail(EXIT_INVALID, "truncated tar archive");
        }
        &self.out[self.pos..self.out_len]
    }

    fn read(&mut self, dst: &mut [u8]) {
        let mut done = 0;
        while done < dst.len() {
            let avail = self.available();
            let k = avail.len().min(dst.len() - done);
            dst[done..done + k].copy_from_slice(&avail[..k]);
            self.pos += k;
            done += k;
        }
    }

    /// streams n bytes into the file (or skips them)
    fn copy(&mut self, mut file: Option<&mut File>, mut n: u64, rel: &[u8]) {
        while n > 0 {
            let avail = self.available();
            let k = (avail.len() as u64).min(n) as usize;
            if let Some(f) = file.as_deref_mut() {
                if let Err(e) = f.write_all(&avail[..k]) {
                    fail(
                        EXIT_IO,
                        format!("cannot write {}: {}", lossy(rel), err_str(&e)),
                    );
                }
            }
            self.pos += k;
            n -= k as u64;
        }
    }
}

/* ---------- paths ---------- */

struct Ctx {
    dest: Vec<u8>,
    last_parent: Option<Vec<u8>>,
    links: Vec<Link>,
    #[cfg_attr(windows, allow(dead_code))]
    link_steps: u64,
}

struct Link {
    rel: Vec<u8>,
    target: Vec<u8>,
    created: bool,
}

impl Ctx {
    fn full_path(&self, rel: &[u8]) -> PathBuf {
        let mut p = Vec::with_capacity(self.dest.len() + rel.len() + 1);
        p.extend_from_slice(&self.dest);
        p.push(b'/');
        p.extend_from_slice(rel);
        to_path(p)
    }

    /// creates every parent directory of rel, refusing to traverse anything that is not a real directory
    fn make_parents(&mut self, rel: &[u8]) {
        let Some(slash) = rel.iter().rposition(|&c| c == b'/') else {
            return;
        };
        let parent = &rel[..slash];
        if self.last_parent.as_deref() == Some(parent) {
            return;
        }
        for i in 0..=parent.len() {
            if i < parent.len() && parent[i] != b'/' {
                continue;
            }
            let sub = &parent[..i];
            let full = self.full_path(sub);
            let mut t = ptype(&full);
            if t == PType::Missing {
                if let Err(e) = fs::create_dir(&full) {
                    if e.kind() != io::ErrorKind::AlreadyExists {
                        fail(
                            EXIT_IO,
                            format!("cannot create directory {}: {}", lossy(sub), err_str(&e)),
                        );
                    }
                }
                t = ptype(&full);
            }
            if t != PType::Dir {
                fail(
                    EXIT_INVALID,
                    format!("{} exists and is not a directory", lossy(sub)),
                );
            }
        }
        self.last_parent = Some(parent.to_vec());
    }

    fn write_file(&mut self, r: &mut Reader, rel: &[u8], size: u64, exec: bool) {
        self.make_parents(rel);
        let full = self.full_path(rel);
        let mut res = create(&full, exec);
        if let Err(e) = &res {
            if exists_error(e, &full) {
                /* later duplicates win, but only ever replace a regular file */
                if ptype(&full) != PType::File {
                    fail(
                        EXIT_INVALID,
                        format!("{} conflicts with an existing entry", lossy(rel)),
                    );
                }
                if let Err(e) = fs::remove_file(&full) {
                    fail(
                        EXIT_IO,
                        format!("cannot replace {}: {}", lossy(rel), err_str(&e)),
                    );
                }
                res = create(&full, exec);
            }
        }
        let mut f = match res {
            Ok(f) => f,
            Err(e) => fail(
                EXIT_IO,
                format!("cannot create {}: {}", lossy(rel), err_str(&e)),
            ),
        };
        r.copy(Some(&mut f), size, rel);
    }

    fn make_dir(&mut self, rel: &[u8]) {
        let mut child = rel.to_vec();
        child.extend_from_slice(b"/x");
        self.make_parents(&child); /* creates rel itself with the same checks */
    }

    /// Walks the link target on the real filesystem, following links like the kernel would, and fails as soon
    /// as any step would leave the destination. Missing components are resolved lexically.
    #[cfg(unix)]
    fn link_stays_inside(&mut self, i: usize) -> bool {
        use std::os::unix::ffi::OsStrExt;
        let rel = &self.links[i].rel;
        let mut cur: Vec<u8> = match rel.iter().rposition(|&c| c == b'/') {
            Some(s) => rel[..s].to_vec(),
            None => Vec::new(),
        };
        let mut rem: Vec<u8> = self.links[i].target.clone();
        let mut p = 0;
        let mut hops = 0;
        while p < rem.len() {
            let s = p;
            while p < rem.len() && rem[p] != b'/' {
                p += 1;
            }
            let comp = rem[s..p].to_vec();
            if p < rem.len() {
                p += 1;
            }
            if comp.is_empty() || comp == b"." {
                continue;
            }
            self.link_steps += 1;
            if self.link_steps > MAX_LINK_STEPS {
                return false;
            }
            if comp == b".." {
                if cur.is_empty() {
                    return false;
                }
                pop_component(&mut cur);
                continue;
            }
            if !cur.is_empty() {
                cur.push(b'/');
            }
            cur.extend_from_slice(&comp);
            if cur.len() >= MAX_REL {
                return false;
            }
            let full = self.full_path(&cur);
            if ptype(&full) == PType::Link {
                let Ok(t) = fs::read_link(&full) else {
                    return false;
                };
                let t = t.as_os_str().as_bytes();
                hops += 1;
                if t.is_empty() || t.len() >= MAX_REL || hops > MAX_LINK_HOPS || t[0] == b'/' {
                    return false;
                }
                pop_component(&mut cur);
                /* continue with the link target followed by what was left */
                let mut nrem = t.to_vec();
                nrem.push(b'/');
                nrem.extend_from_slice(&rem[p..]);
                rem = nrem;
                p = 0;
            }
        }
        true
    }

    fn create_links(&mut self) {
        /* links now appear on disk, so every parent must be checked again */
        self.last_parent = None;
        for i in 0..self.links.len() {
            let rel = self.links[i].rel.clone();
            self.make_parents(&rel);
            let full = self.full_path(&rel);
            make_link(&full, &rel, &self.links[i].target);
            self.links[i].created = true;
        }
    }

    #[cfg(unix)]
    fn remove_links(&self) {
        for l in &self.links {
            if l.created {
                let _ = fs::remove_file(self.full_path(&l.rel));
            }
        }
    }
}

#[cfg(unix)]
fn create(path: &Path, exec: bool) -> io::Result<File> {
    use std::os::unix::fs::OpenOptionsExt;
    #[cfg(target_os = "linux")]
    const O_NOFOLLOW: i32 = if cfg!(any(target_arch = "aarch64", target_arch = "arm")) {
        0o100000
    } else {
        0o400000
    };
    #[cfg(target_vendor = "apple")]
    const O_NOFOLLOW: i32 = 0x100;
    /* O_EXCL never follows a symlink at the final component, O_NOFOLLOW is belt and braces */
    OpenOptions::new()
        .write(true)
        .create_new(true)
        .custom_flags(O_NOFOLLOW)
        .mode(if exec { 0o777 } else { 0o666 })
        .open(path)
}

#[cfg(windows)]
fn create(path: &Path, _exec: bool) -> io::Result<File> {
    OpenOptions::new().write(true).create_new(true).open(path)
}

#[cfg(unix)]
fn make_link(full: &Path, rel: &[u8], target: &[u8]) {
    use std::os::unix::ffi::OsStrExt;
    if let Err(e) = std::os::unix::fs::symlink(std::ffi::OsStr::from_bytes(target), full) {
        if e.kind() == io::ErrorKind::AlreadyExists {
            fail(
                EXIT_INVALID,
                format!("{} conflicts with an existing entry", lossy(rel)),
            );
        }
        fail(
            EXIT_IO,
            format!("cannot create symlink {}: {}", lossy(rel), err_str(&e)),
        );
    }
}

/// no symlinks on Windows: store the target as file content, like git with core.symlinks=false
#[cfg(windows)]
fn make_link(full: &Path, rel: &[u8], target: &[u8]) {
    let mut f = match create(full, false) {
        Ok(f) => f,
        Err(e) if exists_error(&e, full) => fail(
            EXIT_INVALID,
            format!("{} conflicts with an existing entry", lossy(rel)),
        ),
        Err(e) => fail(
            EXIT_IO,
            format!("cannot create {}: {}", lossy(rel), err_str(&e)),
        ),
    };
    if f.write_all(target).is_err() {
        fail(EXIT_IO, format!("cannot write {}", lossy(rel)));
    }
}

/// after a failed exclusive create: Windows reports a permission error rather than "exists" for a directory
fn exists_error(e: &io::Error, full: &Path) -> bool {
    e.kind() == io::ErrorKind::AlreadyExists || ptype(full) != PType::Missing
}

#[cfg(unix)]
fn to_path(p: Vec<u8>) -> PathBuf {
    use std::os::unix::ffi::OsStringExt;
    PathBuf::from(std::ffi::OsString::from_vec(p))
}

/// entry names are bytes, Windows paths UTF-16: names must be UTF-8
#[cfg(windows)]
fn to_path(p: Vec<u8>) -> PathBuf {
    match String::from_utf8(p) {
        Ok(s) => PathBuf::from(s.replace('/', "\\")),
        Err(e) => fail(
            EXIT_INVALID,
            format!("invalid UTF-8 in path: {}", printable(e.as_bytes())),
        ),
    }
}

#[cfg(unix)]
fn arg_bytes(a: std::ffi::OsString) -> Vec<u8> {
    use std::os::unix::ffi::OsStringExt;
    a.into_vec()
}

#[cfg(windows)]
fn arg_bytes(a: std::ffi::OsString) -> Vec<u8> {
    a.to_string_lossy().into_owned().into_bytes()
}

#[cfg(unix)]
fn pop_component(cur: &mut Vec<u8>) {
    match cur.iter().rposition(|&c| c == b'/') {
        Some(s) => cur.truncate(s),
        None => cur.clear(),
    }
}

/// CON, PRN, AUX, NUL, CONIN$, CONOUT$, COM0-9, LPT0-9 (and superscript 1-3), with or without extension
fn is_reserved_windows_name(c: &[u8]) -> bool {
    let mut base = c.iter().position(|&x| x == b'.').unwrap_or(c.len());
    while base > 0 && c[base - 1] == b' ' {
        base -= 1;
    }
    let up = c[..base].to_ascii_uppercase();
    if [&b"CON"[..], b"PRN", b"AUX", b"NUL", b"CONIN$", b"CONOUT$"].contains(&&up[..]) {
        return true;
    }
    if base < 4 || !(up.starts_with(b"COM") || up.starts_with(b"LPT")) {
        return false;
    }
    if base == 4 {
        return up[3].is_ascii_digit();
    }
    /* superscript 1, 2, 3 count as digits too (UTF-8) */
    base == 5 && up[3] == 0xc2 && matches!(up[4], 0xb9 | 0xb2 | 0xb3)
}

/// Normalizes an entry name into "a/b/c" form: drops "." and empty components and rejects anything that
/// could escape the destination or misbehave on the target filesystem.
fn normalize_entry(name: &[u8]) -> Result<Vec<u8>, &'static str> {
    if name.is_empty() {
        return Err("empty path");
    }
    if name[0] == b'/' {
        return Err("absolute path");
    }
    let mut out = Vec::with_capacity(name.len());
    for comp in name.split(|&c| c == b'/') {
        if comp.is_empty() || comp == b"." {
            continue;
        }
        if comp == b".." {
            return Err("parent directory reference");
        }
        if comp.iter().any(|&c| c < 0x20 || c == 0x7f || c == b'\\') {
            return Err("forbidden character");
        }
        if cfg!(windows) {
            if comp.iter().any(|c| b"<>:\"|?*".contains(c)) {
                return Err("character not allowed on Windows");
            }
            if matches!(comp[comp.len() - 1], b'.' | b' ') || is_reserved_windows_name(comp) {
                return Err("name not allowed on Windows");
            }
        }
        if !out.is_empty() {
            out.push(b'/');
        }
        out.extend_from_slice(comp);
    }
    if out.len() >= MAX_REL {
        return Err("path too long");
    }
    Ok(out)
}

fn valid_link_target(t: &[u8]) -> bool {
    !t.is_empty()
        && t[0] != b'/'
        && t.len() < MAX_REL
        && !t.iter().any(|&c| c < 0x20 || c == 0x7f || c == b'\\')
}

/* ---------- tar ---------- */

fn parse_octal(p: &[u8]) -> Option<u64> {
    if p[0] & 0x80 != 0 {
        return None; /* base-256: >8GB entries are not supported */
    }
    let mut i = 0;
    let mut v: u64 = 0;
    while i < p.len() && p[i] == b' ' {
        i += 1;
    }
    let mut digits = 0;
    while i < p.len() && (b'0'..=b'7').contains(&p[i]) {
        if v >> 60 != 0 {
            return None;
        }
        v = (v << 3) | u64::from(p[i] - b'0');
        i += 1;
        digits += 1;
    }
    if p[i..].iter().any(|&c| c != b' ' && c != 0) || digits == 0 {
        return None;
    }
    Some(v)
}

fn cstr(s: &[u8]) -> &[u8] {
    match s.iter().position(|&c| c == 0) {
        Some(p) => &s[..p],
        None => s,
    }
}

/// pax extended header records: "<len> <key>=<value>\n"
fn parse_pax(d: &[u8], path: &mut Option<Vec<u8>>, linkpath: &mut Option<Vec<u8>>) {
    let malformed = || -> ! { fail(EXIT_INVALID, "malformed pax header") };
    let n = d.len();
    let mut pos = 0;
    while pos < n {
        let mut len: usize = 0;
        let mut i = pos;
        while i < n && d[i].is_ascii_digit() {
            if len as u64 > MAX_META {
                malformed();
            }
            len = len * 10 + usize::from(d[i] - b'0');
            i += 1;
        }
        if i >= n
            || d[i] != b' '
            || len == 0
            || len > n - pos
            || d[pos + len - 1] != b'\n'
            || i + 1 > pos + len - 1
        {
            malformed();
        }
        let kv = &d[i + 1..pos + len - 1];
        let Some(eq) = kv.iter().position(|&c| c == b'=') else {
            malformed()
        };
        let (key, value) = (&kv[..eq], &kv[eq + 1..]);
        if value.contains(&0) {
            fail(EXIT_INVALID, "NUL byte in pax value");
        }
        match key {
            b"path" => *path = Some(value.to_vec()),
            b"linkpath" => *linkpath = Some(value.to_vec()),
            b"size" => fail(EXIT_INVALID, "pax size override is not supported"),
            _ => {}
        }
        pos += len;
    }
}

fn extract(ctx: &mut Ctx, f: File, max_size: u64) {
    let mut r = Reader::new(f, max_size);
    let mut h = [0u8; 512];
    let mut long_name: Option<Vec<u8>> = None;
    let mut long_link: Option<Vec<u8>> = None;
    let mut pax_path: Option<Vec<u8>> = None;
    let mut pax_link: Option<Vec<u8>> = None;
    loop {
        r.read(&mut h);
        if h.iter().all(|&c| c == 0) {
            break;
        }
        let sum =
            parse_octal(&h[148..156]).unwrap_or_else(|| fail(EXIT_INVALID, "invalid tar header"));
        let calc: u64 = h
            .iter()
            .enumerate()
            .map(|(i, &c)| {
                if (148..156).contains(&i) {
                    32
                } else {
                    u64::from(c)
                }
            })
            .sum();
        if calc != sum {
            fail(EXIT_INVALID, "tar header checksum mismatch");
        }
        let size = parse_octal(&h[124..136])
            .unwrap_or_else(|| fail(EXIT_INVALID, "unsupported tar entry size"));
        let mode = parse_octal(&h[100..108]).unwrap_or(0o644);
        let typ = h[156];
        let padded = (size + 511) & !511;

        if matches!(typ, b'L' | b'K' | b'x' | b'g') {
            if size > MAX_META {
                fail(EXIT_INVALID, "metadata entry too large");
            }
            let mut d = vec![0u8; size as usize];
            r.read(&mut d);
            r.copy(None, padded - size, b"");
            match typ {
                b'L' => long_name = Some(cstr(&d).to_vec()),
                b'K' => long_link = Some(cstr(&d).to_vec()),
                b'x' => parse_pax(&d, &mut pax_path, &mut pax_link),
                _ => {}
            }
            continue;
        }

        let name: Vec<u8> = if let Some(p) = pax_path.take() {
            p
        } else if let Some(l) = long_name.take() {
            l
        } else if &h[257..263] == b"ustar\0" && h[345] != 0 {
            let mut n = cstr(&h[345..500]).to_vec();
            n.push(b'/');
            n.extend_from_slice(cstr(&h[..100]));
            n
        } else {
            cstr(&h[..100]).to_vec()
        };
        let target: Option<Vec<u8>> = if typ == b'2' {
            Some(
                pax_link
                    .take()
                    .or_else(|| long_link.take())
                    .unwrap_or_else(|| cstr(&h[157..257]).to_vec()),
            )
        } else {
            None
        };
        long_name = None;
        long_link = None;
        pax_path = None;
        pax_link = None;

        let rel = normalize_entry(&name).unwrap_or_else(|why| {
            fail(
                EXIT_INVALID,
                format!("unsafe entry \"{}\": {}", printable(&name), why),
            )
        });
        let empty = || -> ! {
            fail(
                EXIT_INVALID,
                format!("unsafe entry \"{}\": empty path", printable(&name)),
            )
        };

        match typ {
            b'0' | 0 | b'7' => {
                if rel.is_empty() {
                    empty();
                }
                ctx.write_file(&mut r, &rel, size, mode & 0o111 != 0);
                r.copy(None, padded - size, &rel);
            }
            b'5' => {
                if !rel.is_empty() {
                    ctx.make_dir(&rel);
                }
                r.copy(None, padded, &rel);
            }
            b'2' => {
                if rel.is_empty() {
                    empty();
                }
                let target = target.unwrap_or_default();
                if !valid_link_target(&target) {
                    fail(
                        EXIT_INVALID,
                        format!(
                            "unsafe symlink \"{}\" -> \"{}\"",
                            printable(&name),
                            printable(&target)
                        ),
                    );
                }
                r.copy(None, padded, &rel);
                ctx.links.push(Link {
                    rel,
                    target,
                    created: false,
                });
            }
            _ => {
                let c = if typ == 0 {
                    '0'
                } else if typ > 0x20 && typ < 0x7f {
                    typ as char
                } else {
                    '?'
                };
                fail(
                    EXIT_INVALID,
                    format!(
                        "unsupported entry type '{}' for \"{}\"",
                        c,
                        printable(&name)
                    ),
                );
            }
        }
    }

    /* the whole stream must be valid, which also verifies the checksum of the last frame */
    r.pos = r.out_len;
    while r.fill() {
        r.pos = r.out_len;
    }

    /* links last, so no file is ever written through one */
    ctx.create_links();
    #[cfg(unix)]
    for i in 0..ctx.links.len() {
        if !ctx.link_stays_inside(i) {
            ctx.remove_links();
            if ctx.link_steps > MAX_LINK_STEPS {
                fail(EXIT_INVALID, "symlinks take too many steps to verify");
            }
            let l = &ctx.links[i];
            fail(
                EXIT_INVALID,
                format!(
                    "unsafe symlink \"{}\" -> \"{}\": resolves outside of the destination",
                    lossy(&l.rel),
                    lossy(&l.target)
                ),
            );
        }
    }
}

fn main() {
    #[cfg(target_os = "linux")]
    relro::protect_relro();
    let args: Vec<Vec<u8>> = std::env::args_os().map(arg_bytes).collect();
    let mut args = &args[..];
    if args.len() == 2 && args[1] == b"--version" {
        let libc = option_env!("EXTRACT_LIBC")
            .map(|l| format!(", {l}"))
            .unwrap_or_default();
        println!(
            "zstd-extract {} (zstd {}, rustc {}{})",
            EXTRACT_VERSION,
            zstd::version(),
            env!("EXTRACT_RUSTC"),
            libc
        );
        return;
    }
    let mut max_size = u64::MAX;
    if args.len() == 4 && args[1].starts_with(b"--max-size=") {
        let p = &args[1][11..];
        if p.is_empty() || !p.iter().all(u8::is_ascii_digit) {
            fail(EXIT_USAGE, "invalid --max-size");
        }
        max_size = std::str::from_utf8(p)
            .ok()
            .and_then(|s| s.parse().ok())
            .unwrap_or_else(|| fail(EXIT_USAGE, "invalid --max-size"));
        args = &args[1..];
    }
    if args.len() != 3 {
        eprintln!("Usage: zstd-extract [--max-size=<bytes>] <archive.tar.zst> <destination>");
        exit(EXIT_USAGE);
    }

    let mut dl = args[2].len();
    while dl > 1 && (args[2][dl - 1] == b'/' || (cfg!(windows) && args[2][dl - 1] == b'\\')) {
        dl -= 1;
    }
    let dest = args[2][..dl].to_vec();
    let dest_path = to_path(dest.clone());
    match ptype(&dest_path) {
        PType::Missing => {
            if let Err(e) = fs::create_dir(&dest_path) {
                if e.kind() != io::ErrorKind::AlreadyExists {
                    fail(
                        EXIT_IO,
                        format!(
                            "cannot create destination {}: {}",
                            lossy(&dest),
                            err_str(&e)
                        ),
                    );
                }
            }
        }
        PType::Dir
            if fs::read_dir(&dest_path)
                .map(|mut d| d.next().is_none())
                .unwrap_or(false) => {}
        _ => fail(
            EXIT_USAGE,
            format!(
                "destination {} must be an empty directory or not exist",
                lossy(&dest)
            ),
        ),
    }

    let f = File::open(to_path(args[1].clone())).unwrap_or_else(|e| {
        fail(
            EXIT_IO,
            format!("cannot open {}: {}", lossy(&args[1]), err_str(&e)),
        )
    });
    let mut ctx = Ctx {
        dest,
        last_parent: None,
        links: Vec::new(),
        link_steps: 0,
    };
    extract(&mut ctx, f, max_size);
}

/// reads into buf, retrying on EINTR
pub fn read_some(f: &mut File, buf: &mut [u8]) -> usize {
    loop {
        match f.read(buf) {
            Ok(n) => return n,
            Err(e) if e.kind() == io::ErrorKind::Interrupted => continue,
            Err(_) => fail(EXIT_IO, "read error"),
        }
    }
}
