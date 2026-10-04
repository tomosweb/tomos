import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const source = fs.readFileSync(new URL("../post/assets/write-handoff.js", import.meta.url), "utf8");
const WRITE_ORIGIN = "https://tomoswords.org";
const session = "0123456789abcdef0123456789abcdef";
const outbound = [];
const importCalls = [];
const listeners = [];
const writeSource = {
  closed: false,
  postMessage(message, targetOrigin) {
    if (listeners.length === 0) throw new Error("publish-ready was sent before the message listener was registered");
    outbound.push({ message, targetOrigin });
  },
};
const otherSource = {
  closed: false,
  postMessage(message, targetOrigin) {
    outbound.push({ message, targetOrigin });
  },
};

const windowObject = {
  opener: writeSource,
  location: {
    href: `https://tomoswords.sakura.ne.jp/tomos-edit/post/?write_import=1&session=${session}`,
    pathname: "/tomos-edit/post/",
    search: `?write_import=1&session=${session}`,
    hash: "",
  },
  matchMedia: () => ({ matches: false }),
  addEventListener(type, listener) {
    if (type === "message") listeners.push(listener);
  },
  dispatchEvent() {},
  setTimeout,
  clearTimeout,
  history: { replaceState() {} },
  alert() {},
  TomosPostImportMarkdown: async (markdown, filename) => {
    importCalls.push({ markdown, filename });
    return true;
  },
};

const documentObject = {
  body: {
    dataset: {
      tomosWriteUrl: `${WRITE_ORIGIN}/write/`,
      tomosVersion: "1.1.7",
      tomosMarkdownMaxBytes: "1048576",
    },
  },
  querySelectorAll: () => [],
  getElementById(id) {
    if (id === "markdown_file") return {};
    if (id === "post-upload") return { scrollIntoView() {} };
    return null;
  },
};

const context = {
  console,
  CustomEvent: class CustomEvent {},
  Date,
  Promise,
  Set,
  TextEncoder,
  Uint8Array,
  URL,
  URLSearchParams,
  document: documentObject,
  window: windowObject,
};
vm.runInNewContext(source, context, { filename: "write-handoff.js" });

assert.equal(listeners.length, 1, "message listener must be registered during initialization");
assert.equal(outbound.length, 0, "initialization must not send ready to window.opener");

const dispatch = (sourceWindow, message, origin = WRITE_ORIGIN) => {
  listeners[0]({ source: sourceWindow, origin, data: message });
};
const envelope = (message) => ({ protocol: "tomos-write-handoff/v1", session, ...message });

dispatch(otherSource, envelope({ type: "write:publish-probe" }), "https://evil.example");
dispatch(otherSource, { ...envelope({ type: "write:publish-probe" }), protocol: "wrong/v1" });
dispatch(otherSource, { ...envelope({ type: "write:publish-probe" }), session: "bad-session" });
assert.equal(outbound.length, 0, "origin, protocol, and session mismatches must be rejected");

dispatch(writeSource, envelope({ type: "write:publish-probe", senderVersion: "0.4.0" }));
assert.equal(outbound.length, 1, "the first valid probe must receive publish-ready");
assert.equal(outbound[0].targetOrigin, WRITE_ORIGIN);
assert.equal(outbound[0].message.type, "tomos:publish-ready");
assert.equal(outbound[0].message.session, session);

dispatch(otherSource, envelope({ type: "write:publish-probe" }));
assert.equal(outbound.length, 1, "a different source must be rejected after source binding");

dispatch(otherSource, envelope({
  type: "write:publish-document",
  transactionId: "other-transaction",
  document: { markdown: "# rejected", filename: "rejected.md" },
}));
await new Promise((resolve) => setTimeout(resolve, 0));
assert.equal(importCalls.length, 0, "a document from a different source must not reach the importer");

dispatch(writeSource, envelope({
  type: "write:publish-document",
  transactionId: "transaction-1",
  document: { markdown: "# accepted", filename: "accepted.md" },
}));
await new Promise((resolve) => setTimeout(resolve, 0));
assert.deepEqual(importCalls, [{ markdown: "# accepted", filename: "accepted.md" }]);
assert.equal(outbound[1].message.type, "tomos:publish-ack");
assert.equal(outbound[1].message.transactionId, "transaction-1");
assert.equal(outbound[1].targetOrigin, WRITE_ORIGIN);

dispatch(writeSource, envelope({
  type: "write:publish-document",
  transactionId: "transaction-2",
  document: { markdown: "a".repeat(1048577), filename: "too-large.md" },
}));
await new Promise((resolve) => setTimeout(resolve, 0));
assert.equal(importCalls.length, 1, "Markdown over 1 MiB must be rejected");
assert.equal(outbound.length, 2, "over-limit Markdown must not receive an ACK");

console.log("post_write_new_article_handoff_runtime_check: listener ordering, source binding, validation, import, ACK, and size checks passed");
