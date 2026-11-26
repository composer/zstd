//! The zstd decoder: zrip's push decoder, which has no unsafe code.

use zrip_decode::push::{PushDecoder, Status};

pub const VERSION: &str = env!("EXTRACT_ZRIP");

/// input chunk size: larger than a block, so fewer blocks straddle two reads and get staged by the decoder
pub const IN_SIZE: usize = 1 << 20;
/// output chunk size, a full block
pub const OUT_SIZE: usize = 128 << 10;

pub struct Stream {
    dec: PushDecoder,
    pending: bool,
}

impl Stream {
    pub fn new(window_log_max: u32) -> Stream {
        // the caller enforces its own output limit
        Stream {
            dec: PushDecoder::with_limit(usize::MAX).max_window(1 << window_log_max),
            pending: false,
        }
    }

    /// Decompresses from input[*pos..] into out, advancing *pos. Returns the bytes written.
    pub fn decompress(
        &mut self,
        input: &[u8],
        pos: &mut usize,
        out: &mut [u8],
    ) -> Result<usize, String> {
        let p = self
            .dec
            .decode(&input[*pos..], out)
            .map_err(|e| e.to_string())?;
        *pos += p.consumed;
        self.pending = p.status == Status::NeedsOutput;
        Ok(p.produced)
    }

    /// decoded output is waiting, to be fetched before reading more input
    pub fn has_pending_output(&self) -> bool {
        self.pending
    }

    /// at the end of the input: fails if a frame is incomplete
    pub fn finish(&self) -> Result<(), String> {
        self.dec.finish().map_err(|e| e.to_string())
    }
}
