# Tomos Post ↔ Tomos Write handoff baseline

This record fixes the Phase 0 baseline for Issue #237. It describes the released pair before the v1.0.4 candidate changes.

## Exact sources

- Tomos public stable v1.0.3: `tomosweb/tomos` tag `v1.0.3`, dereferenced commit `a966012bdf052b3c41ecac7a5a66a0b0990541b1`
- Tomos development candidate base: `tomosweb/tomos-dev` `origin/main` at `fb6b30fa93137bc084f94dbed76ed69c6a692206`
- Tomos Write stable v0.3.2: `tomosweb/tomos-write` tag `v0.3.2`, commit `f163e495faef610eaed26b81620104b2c229332a`
- Canonical Write URL: `https://tomoswords.org/write/`
- Protocol: `tomos-write-handoff/v1`

## Released message sequence

```text
write:ready             Write -> Tomos Post
tomos:document          Tomos Post -> Write
write:import-ack        Write -> Tomos Post
write:return-probe     Write -> Tomos Post
tomos:return-ready     Tomos Post -> Write
write:return-document  Write -> Tomos Post
tomos:return-ack       Tomos Post -> Write
```

The released implementation validates the exact peer origin, session, and source window. Return URLs must be HTTPS, same-origin with the Tomos source, and must not contain username/password data. Tomos Post authentication and CSRF validation are performed before the server prepares the editable Markdown. The Markdown handoff limit is 2 MiB at the browser boundary.

## Released delivery behavior

| Message | Released behavior | Re-send status before this candidate |
| --- | --- | --- |
| `write:ready` | Sent once when the Write receiver initializes | Not retried; a lost signal leaves Post waiting |
| `tomos:document` | Sent once after `write:ready` | Not retried; no transaction identity |
| `write:import-ack` | Sent once after Write imports through `DataTransfer` | Not retried; duplicate document has no idempotency key |
| `write:return-probe` | Sent while probing an existing receiver for up to the fixed 1.5 second window | Bounded probe only; fixed short window |
| `tomos:return-ready` | Sent on receiver initialization and in response to a probe | No transaction identity |
| `write:return-document` | Sent once after ready | Not retried; ACK loss is indistinguishable from import failure |
| `tomos:return-ack` | Sent once after Post sets the file input through `DataTransfer` | Not retried; duplicate return can re-trigger file handling |

The other relevant released time limits are the Tomos outbound 10 second handshake timer and the Write return 10 second ACK timer. The primary Markdown import path on both sides emulates a file input with `DataTransfer`.

## Phase 0.5 live-site observation

On 2026-09-17, the currently published `https://tomoswords.org/` site was opened in desktop Chromium while authenticated. The public Post page rendered the published article list and the `Tomos Writeで編集` controls. One launch was attempted and the confirmation prompt was accepted; the browser automation session then lost its controllable focus before the Write import/return flow completed. No article was edited, published, or deployed by this check. This is evidence that the released entry point was reachable, not a completed end-to-end PASS. Safari, iPhone Safari, custom-domain Tomos, subdirectory installation, and five consecutive round trips remain Human Gate work.

## Existing security and deployment boundaries

- `post/.htaccess` keeps `Cross-Origin-Opener-Policy: same-origin-allow-popups` for the cross-origin opener relationship.
- Subdirectory Post URLs are generated through Tomos URL helpers; the return URL is validated before it is put in the handoff fragment.
- No article body, CSRF token, password, or authentication data is written to external diagnostics.

## Candidate extension

The candidate keeps `tomos-write-handoff/v1` and adds optional `senderVersion`, `capabilities`, and `transactionId` fields. Receivers continue accepting messages without those optional fields so an N / N-1 peer can complete the basic Markdown handoff. Major transmissions use bounded retry with a stable transaction ID and duplicate suppression. Post and Write expose direct Markdown import entries; the existing file-upload UI remains available as the normal manual route and the final fallback.

## Candidate Markdown size contract

The released browser handoff used a 2 MiB boundary, while Tomos Post's product upload contract is `PostUploadInput::MAX_BYTES = 1048576` bytes and rejects larger Markdown. The candidate therefore exposes that existing 1 MiB value to the Post handoff scripts and uses it for the server handoff response, direct import, and return receiver. Exactly 1 MiB is accepted; any larger automatic transmission is not ACKed or treated as successful. Write keeps an over-limit draft locally within the existing 2 MiB recovery ceiling so the editor content remains available for Markdown download fallback; that recovery ceiling is not a transport or Post-import success limit.
