//! Records the rustc and zrip-decode versions for --version.

use std::env;
use std::process::Command;

fn main() {
    println!("cargo:rerun-if-changed=Cargo.lock");
    println!("cargo:rerun-if-env-changed=EXTRACT_LIBC");

    let lock = std::fs::read_to_string("Cargo.lock").unwrap();
    let zrip = lock
        .split("[[package]]")
        .find(|p| p.contains("name = \"zrip-decode\""))
        .and_then(|p| p.lines().find_map(|l| l.strip_prefix("version = \"")))
        .and_then(|v| v.strip_suffix('"'))
        .expect("zrip-decode in Cargo.lock");
    println!("cargo:rustc-env=EXTRACT_ZRIP={zrip}");

    let rustc = env::var("RUSTC").unwrap();
    let v = Command::new(rustc)
        .arg("--version")
        .output()
        .unwrap()
        .stdout;
    let v = String::from_utf8(v).unwrap();
    println!(
        "cargo:rustc-env=EXTRACT_RUSTC={}",
        v.split_whitespace().nth(1).unwrap()
    );
}
