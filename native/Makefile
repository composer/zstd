# Builds the zstd-extract binary for the current platform into build/.
#   make linux    -> build/zstd-extract-linux-$(uname -m), statically linked against the system's libc;
#                    released binaries use musl instead, built with build-linux.sh
#   make darwin   -> build/zstd-extract-darwin, universal x86_64 + arm64, ad-hoc signed
# The release builds also write <binary>.toolchain, the versions the SBOM is generated from (.github/scripts/sbom.sh).
# Windows is built with build-windows.cmd (MSVC).

ZSTD_SRC := $(wildcard zstd/common/*.c) $(wildcard zstd/decompress/*.c)
SRC := src/main.c $(ZSTD_SRC)
CFLAGS ?= -O2
CFLAGS += -std=c99 -Wall -Wextra -Izstd -DZSTD_LEGACY_SUPPORT=0 -DZSTD_MULTITHREAD=0 -DXXH_NAMESPACE=ZSTD_ \
	-DZSTD_NO_TRACE -DDEBUGLEVEL=0 -ffunction-sections -fdata-sections
# the binary parses untrusted archives: keep exploit mitigations on whatever the compiler defaults are
CFLAGS += -fstack-protector-strong -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=2 -fPIE
# statically linked libc, shown by --version
ifdef LIBC
CFLAGS += -DEXTRACT_LIBC='"$(LIBC)"'
endif
ARCH := $(shell uname -m | sed 's/arm64/aarch64/')

.PHONY: linux darwin clean

linux: build/zstd-extract-linux-$(ARCH)

build/zstd-extract-linux-$(ARCH): $(SRC) zstd/decompress/huf_decompress_amd64.S
	mkdir -p build
	$(CC) $(CFLAGS) -static-pie -Wl,--gc-sections -Wl,-z,relro,-z,now,-z,noexecstack -s -o $@ $(SRC) zstd/decompress/huf_decompress_amd64.S

darwin: build/zstd-extract-darwin

build/zstd-extract-darwin: $(SRC)
	mkdir -p build
	$(CC) $(CFLAGS) -arch x86_64 -arch arm64 -mmacosx-version-min=11.0 -Wl,-dead_strip -o $@ $(SRC) zstd/decompress/huf_decompress_amd64.S
	strip -x $@
	codesign --force --sign - $@
	{ echo "clang=$$($(CC) --version | sed -n "1s/.*version //p")"; echo "sdk=$$(xcrun --show-sdk-version)"; } > $@.toolchain

clean:
	rm -rf build
