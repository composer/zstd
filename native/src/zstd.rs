//! Minimal safe wrapper around the vendored libzstd streaming decoder.

use std::ffi::{c_char, c_int, c_void, CStr};
use std::ptr::NonNull;

#[repr(C)]
struct InBuffer {
    src: *const c_void,
    size: usize,
    pos: usize,
}

#[repr(C)]
struct OutBuffer {
    dst: *mut c_void,
    size: usize,
    pos: usize,
}

enum DStream {}

const ZSTD_D_WINDOW_LOG_MAX: c_int = 100;

extern "C" {
    fn ZSTD_createDStream() -> *mut DStream;
    fn ZSTD_freeDStream(zds: *mut DStream) -> usize;
    fn ZSTD_DCtx_setParameter(dctx: *mut DStream, param: c_int, value: c_int) -> usize;
    fn ZSTD_decompressStream(
        zds: *mut DStream,
        output: *mut OutBuffer,
        input: *mut InBuffer,
    ) -> usize;
    fn ZSTD_DStreamInSize() -> usize;
    fn ZSTD_DStreamOutSize() -> usize;
    fn ZSTD_isError(code: usize) -> u32;
    fn ZSTD_getErrorName(code: usize) -> *const c_char;
    fn ZSTD_versionString() -> *const c_char;
}

fn static_str(p: *const c_char) -> &'static str {
    // SAFETY: libzstd returns pointers to static NUL-terminated ASCII strings
    unsafe { CStr::from_ptr(p) }.to_str().unwrap_or("?")
}

pub fn version() -> &'static str {
    // SAFETY: no preconditions
    static_str(unsafe { ZSTD_versionString() })
}

pub fn in_size() -> usize {
    // SAFETY: no preconditions
    unsafe { ZSTD_DStreamInSize() }
}

pub fn out_size() -> usize {
    // SAFETY: no preconditions
    unsafe { ZSTD_DStreamOutSize() }
}

pub struct Stream(NonNull<DStream>);

impl Stream {
    pub fn new(window_log_max: c_int) -> Option<Stream> {
        // SAFETY: no preconditions; a null result is handled
        let s = Stream(NonNull::new(unsafe { ZSTD_createDStream() })?);
        // SAFETY: s.0 is a live stream
        let r =
            unsafe { ZSTD_DCtx_setParameter(s.0.as_ptr(), ZSTD_D_WINDOW_LOG_MAX, window_log_max) };
        // SAFETY: plain function of its argument
        if unsafe { ZSTD_isError(r) } != 0 {
            return None;
        }
        Some(s)
    }

    /// Decompresses from input[*pos..] into out, advancing *pos. Returns the bytes written and zstd's hint,
    /// which is 0 at the end of a frame.
    pub fn decompress(
        &mut self,
        input: &[u8],
        pos: &mut usize,
        out: &mut [u8],
    ) -> Result<(usize, usize), &'static str> {
        assert!(*pos <= input.len());
        let mut i = InBuffer {
            src: input.as_ptr().cast(),
            size: input.len(),
            pos: *pos,
        };
        let mut o = OutBuffer {
            dst: out.as_mut_ptr().cast(),
            size: out.len(),
            pos: 0,
        };
        // SAFETY: both buffers describe live slices with pos <= size; libzstd only writes dst[pos..size]
        let r = unsafe { ZSTD_decompressStream(self.0.as_ptr(), &mut o, &mut i) };
        // SAFETY: plain function of its argument
        if unsafe { ZSTD_isError(r) } != 0 {
            // SAFETY: plain function of its argument
            return Err(static_str(unsafe { ZSTD_getErrorName(r) }));
        }
        assert!(i.pos <= input.len() && o.pos <= out.len());
        *pos = i.pos;
        Ok((o.pos, r))
    }
}

impl Drop for Stream {
    fn drop(&mut self) {
        // SAFETY: self.0 is a live stream, freed once
        unsafe { ZSTD_freeDStream(self.0.as_ptr()) };
    }
}
