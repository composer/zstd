//! What std does not expose on unix.

use std::ffi::c_int;
use std::fs::File;
use std::io;
use std::os::fd::IntoRawFd;

extern "C" {
    #[link_name = "close"]
    fn c_close(fd: c_int) -> c_int;
}

/// Closes the file, returning the error that dropping it would ignore.
pub fn close(f: File) -> io::Result<()> {
    let fd = f.into_raw_fd();
    // SAFETY: fd is owned and open, into_raw_fd() gave up File's ownership so it is closed exactly once
    if unsafe { c_close(fd) } == 0 {
        Ok(())
    } else {
        Err(io::Error::last_os_error())
    }
}
