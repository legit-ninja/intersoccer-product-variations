# Repro: "Invalid or unexpected token" on every front-end page (local sandbox)

Read-only. Anonymous GETs only; no login, no DB writes.

- `node scan.js <local-base-url> <out-prefix> / /shop/<camp>/ /cart/ /checkout/`
  compiles every inline <script> and every same-host external script with vm.Script and prints syntax errors with
  page line, column and any non-ASCII bytes on the failing line. Skips .php under wp-content/plugins/.
- `node verify.js <local-base-url> /checkout/` takes the TikTok pixel block and compiles it three ways: as-is,
  with the truncated text restored, and with only the ellipsis removed.

Finding: the only failing script is the TikTok pixel inline block from the Insert Headers and Footers plugin's header
option (`ihaf_insert_header`). It contains a literal U+2026 (UTF-8 e2 80 a6) where ~35 chars of the
official snippet were cut ("tt… Date" instead of "ttq._t=ttq._t||{},ttq._t[e]=+new Date").
