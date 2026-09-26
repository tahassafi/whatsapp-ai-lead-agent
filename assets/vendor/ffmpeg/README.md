# ffmpeg.wasm (not committed)

The dashboard's in-browser voice-note converter loads ffmpeg.wasm from this
folder. The binaries are ~32 MB, so they are **not** committed to the repo
(`vendor/` is gitignored) — download them yourself:

Required files (place them directly in this folder):

- `ffmpeg.js` and `814.ffmpeg.js` — from the `@ffmpeg/ffmpeg` UMD build
- `util.js` — from the `@ffmpeg/util` UMD build
- `ffmpeg-core.js` and `ffmpeg-core.wasm` — from the `@ffmpeg/core` UMD build

Get them from npm (https://www.npmjs.com/package/@ffmpeg/ffmpeg, `@ffmpeg/util`,
`@ffmpeg/core` — the `dist/umd/` files) or from unpkg, e.g.:

```
https://unpkg.com/@ffmpeg/ffmpeg@0.12.10/dist/umd/ffmpeg.js
https://unpkg.com/@ffmpeg/ffmpeg@0.12.10/dist/umd/814.ffmpeg.js
https://unpkg.com/@ffmpeg/util@0.12.1/dist/umd/index.js   (save as util.js)
https://unpkg.com/@ffmpeg/core@0.12.6/dist/umd/ffmpeg-core.js
https://unpkg.com/@ffmpeg/core@0.12.6/dist/umd/ffmpeg-core.wasm
```

They are self-hosted (instead of loaded from a CDN) because ffmpeg.wasm needs
same-origin worker scripts on most shared-hosting CSP setups.

Without these files, everything still works except the "record a voice note in
the browser" flow in the lead view — sending pre-recorded mp3/ogg files via the
📎 button still works, and servers with native ffmpeg convert uploads
server-side anyway (see `lead_wa_send_voice()`).
