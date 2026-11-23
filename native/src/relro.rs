//! musl's static-pie startup leaves the RELRO segment (GOT, init/fini arrays) writable, unlike a dynamic
//! loader: make it read-only before touching any input.

use std::ffi::{c_char, c_int, c_long, c_void};

#[cfg(not(target_pointer_width = "64"))]
compile_error!("only 64-bit ELF is handled");

#[repr(C)]
struct Phdr {
    p_type: u32,
    p_flags: u32,
    p_offset: u64,
    p_vaddr: u64,
    p_paddr: u64,
    p_filesz: u64,
    p_memsz: u64,
    p_align: u64,
}

// leading fields of struct dl_phdr_info, the only ones read
#[repr(C)]
struct PhdrInfo {
    addr: usize,
    name: *const c_char,
    phdr: *const Phdr,
    phnum: u16,
}

const PT_GNU_RELRO: u32 = 0x6474e552;
const PROT_READ: c_int = 1;
const SC_PAGESIZE: c_int = 30; // the same in glibc and musl

extern "C" {
    fn dl_iterate_phdr(
        cb: extern "C" fn(*mut PhdrInfo, usize, *mut c_void) -> c_int,
        data: *mut c_void,
    ) -> c_int;
    fn mprotect(addr: *mut c_void, len: usize, prot: c_int) -> c_int;
    fn sysconf(name: c_int) -> c_long;
}

extern "C" fn protect(info: *mut PhdrInfo, _size: usize, _data: *mut c_void) -> c_int {
    // SAFETY: dl_iterate_phdr passes a valid info whose phdr array has phnum entries
    let info = unsafe { &*info };
    let phdrs = unsafe { std::slice::from_raw_parts(info.phdr, usize::from(info.phnum)) };
    // SAFETY: no preconditions
    let page = unsafe { sysconf(SC_PAGESIZE) } as usize;
    for ph in phdrs.iter().filter(|ph| ph.p_type == PT_GNU_RELRO) {
        let start = (info.addr + ph.p_vaddr as usize) & !(page - 1);
        let end = (info.addr + (ph.p_vaddr + ph.p_memsz) as usize) & !(page - 1);
        if end > start {
            // SAFETY: page-aligned range inside our own mapped image; only drops write access
            unsafe { mprotect(start as *mut c_void, end - start, PROT_READ) };
        }
    }
    1 // the first object is the executable itself
}

pub fn protect_relro() {
    // SAFETY: the callback matches the expected signature and ignores data
    unsafe { dl_iterate_phdr(protect, std::ptr::null_mut()) };
}
