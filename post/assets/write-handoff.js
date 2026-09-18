(() => {
  "use strict";

  const PROTOCOL = "tomos-write-handoff/v1";
  const writeUrlValue = document.body?.dataset?.tomosWriteUrl || "";
  let WRITE_URL = "";
  let WRITE_ORIGIN = "";
  try {
    const writeUrl = new URL(writeUrlValue);
    if (writeUrl.protocol === "https:" && !writeUrl.username && !writeUrl.password && writeUrl.pathname === "/write/") {
      WRITE_URL = writeUrl.href;
      WRITE_ORIGIN = writeUrl.origin;
    }
  } catch {
    // A missing or malformed server-provided endpoint fails closed.
  }

  const TOMOS_VERSION = document.body?.dataset?.tomosVersion || "unknown";
  const CAPABILITIES = ["ack", "bounded-retry", "transaction-id", "direct-markdown-import", "diagnostics"];
  const configuredMarkdownMaxBytes = Number(document.body?.dataset?.tomosMarkdownMaxBytes || 0);
  const MAX_MARKDOWN_BYTES = Number.isSafeInteger(configuredMarkdownMaxBytes) && configuredMarkdownMaxBytes > 0
    ? configuredMarkdownMaxBytes
    : 0;
  const MAX_HANDSHAKE_MS = 30000;
  const RETRY_DELAYS_MS = [800, 1500, 3000, 5000, 8000];
  const scrollBehavior = typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth";

  let writeWindow = null;
  let sessionId = "";
  let selectedButton = null;
  let pendingDocument = null;
  let documentTransactionId = "";
  let documentAttempts = 0;
  let documentDeadline = 0;
  let readyReceived = false;
  let writeVersion = "unknown";
  let readyProbeTimer = null;
  let documentRetryTimer = null;
  const processedReturnTransactions = new Set();
  const inflightReturnTransactions = new Set();
  const diagnostics = [];

  const byteLength = (value) => new TextEncoder().encode(value).byteLength;

  const recordDiagnostic = (stage, retryCount = 0) => {
    const entry = {
      stage,
      retryCount,
      protocol: PROTOCOL,
      tomosVersion: TOMOS_VERSION,
      writeVersion,
      timestamp: new Date().toISOString(),
    };
    diagnostics.push(entry);
    window.__tomosHandoffDiagnostics = diagnostics.slice(-50);
    window.dispatchEvent(new CustomEvent("tomos:handoff-diagnostic", { detail: entry }));
  };

  const randomId = () => {
    if (window.crypto && typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  };

  const clearTimer = (timer) => {
    if (timer !== null) window.clearTimeout(timer);
  };

  const clearTransportTimers = () => {
    clearTimer(readyProbeTimer);
    clearTimer(documentRetryTimer);
    readyProbeTimer = null;
    documentRetryTimer = null;
  };

  const setButtonState = (label, disabled) => {
    if (!selectedButton) return;
    selectedButton.textContent = label;
    selectedButton.disabled = disabled;
  };

  const failLaunch = (message) => {
    clearTransportTimers();
    recordDiagnostic("recoverable failure", documentAttempts);
    setButtonState("Tomos Writeで編集", false);
    window.alert(`${message}\n必要な場合は「Markdownを取得」から従来の方法で編集できます。`);
  };

  const safeReturnUrl = (value) => {
    try {
      const url = new URL(value, window.location.href);
      if (url.protocol !== "https:" || url.origin !== window.location.origin || url.username || url.password) return "";
      return `${url.origin}${url.pathname}${url.search}`;
    } catch {
      return "";
    }
  };

  const envelope = (message) => ({
    ...message,
    protocol: PROTOCOL,
    session: sessionId,
    senderVersion: TOMOS_VERSION,
    capabilities: CAPABILITIES,
  });

  const returnEnvelope = (session, message) => ({
    ...message,
    protocol: PROTOCOL,
    session,
    senderVersion: TOMOS_VERSION,
    capabilities: CAPABILITIES,
  });

  const sendReadyProbe = () => {
    if (!writeWindow || writeWindow.closed || !WRITE_ORIGIN) return false;
    writeWindow.postMessage(envelope({ type: "tomos:ready-probe" }), WRITE_ORIGIN);
    return true;
  };

  const startReadyProbe = () => {
    const startedAt = Date.now();
    const probe = () => {
      if (readyReceived || !writeWindow || writeWindow.closed) return;
      if (Date.now() - startedAt >= MAX_HANDSHAKE_MS) {
        failLaunch("Tomos Writeとの接続を確認できませんでした。時間を置いて再試行してください。");
        return;
      }
      sendReadyProbe();
      readyProbeTimer = window.setTimeout(probe, 750);
    };
    probe();
  };

  const sendDocumentAttempt = () => {
    if (!pendingDocument || !writeWindow || writeWindow.closed || !WRITE_ORIGIN) {
      failLaunch("Tomos Writeへの接続が閉じられました。編集内容はMarkdownとして取得できます。");
      return;
    }
    if (documentAttempts >= RETRY_DELAYS_MS.length || Date.now() >= documentDeadline) {
      failLaunch("Tomos Writeへ原稿を送信できませんでした。時間を置いて再試行してください。");
      return;
    }

    documentAttempts += 1;
    writeWindow.postMessage(envelope({
      type: "tomos:document",
      transactionId: documentTransactionId,
      document: pendingDocument,
    }), WRITE_ORIGIN);
    recordDiagnostic("document sent", documentAttempts - 1);
    documentRetryTimer = window.setTimeout(sendDocumentAttempt, RETRY_DELAYS_MS[documentAttempts - 1]);
  };

  const maybeSendDocument = () => {
    if (!readyReceived || !pendingDocument || documentAttempts > 0) return;
    sendDocumentAttempt();
  };

  const launchWrite = async (button) => {
    const article = button.closest(".editable-result");
    const downloadForm = article && article.querySelector('form input[name="action"][value="download_published_markdown"]')?.form;
    const tokenInput = downloadForm && downloadForm.querySelector('input[name="_token"]');
    const contentPathInput = downloadForm && downloadForm.querySelector('input[name="content_path"]');
    const returnUrl = safeReturnUrl(button.dataset.returnUrl || "");
    if (!tokenInput || !contentPathInput || !returnUrl || !WRITE_URL) {
      window.alert("Tomos Writeへ渡す原稿情報を確認できませんでした。一覧を再読み込みしてください。");
      return;
    }

    const approved = window.confirm(
      "この記事のMarkdownをTomos公式サイトのTomos Writeへ渡します。\n\n編集内容は自動では公開されません。Tomosへ戻した後に更新内容を確認できます。"
    );
    if (!approved) return;

    sessionId = randomId();
    selectedButton = button;
    pendingDocument = null;
    documentTransactionId = randomId();
    documentAttempts = 0;
    documentDeadline = Date.now() + MAX_HANDSHAKE_MS;
    readyReceived = false;
    clearTransportTimers();
    recordDiagnostic("session created");
    setButtonState("Tomos Writeを開いています…", true);

    const fragment = new URLSearchParams({
      tomosHandoff: "1",
      session: sessionId,
      sourceOrigin: window.location.origin,
      sourceUrl: returnUrl,
    });
    writeWindow = window.open(`${WRITE_URL}#${fragment.toString()}`, "_blank");
    if (!writeWindow) {
      failLaunch("Tomos Writeを開けませんでした。ブラウザのポップアップ設定を確認してください。");
      return;
    }
    recordDiagnostic("Write opened");
    startReadyProbe();

    try {
      const data = new FormData();
      data.append("_token", tokenInput.value);
      data.append("content_path", contentPathInput.value);
      const response = await fetch("write-handoff.php", {
        method: "POST",
        body: data,
        credentials: "same-origin",
        cache: "no-store",
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || !payload.ok) {
        throw new Error(payload && payload.message ? payload.message : "編集用Markdownを準備できませんでした。");
      }
      if (typeof payload.markdown !== "string" || byteLength(payload.markdown) > MAX_MARKDOWN_BYTES) {
        throw new Error("編集用Markdownを安全に渡せませんでした。");
      }
      pendingDocument = {
        filename: typeof payload.filename === "string" ? payload.filename : "article.md",
        markdown: payload.markdown,
      };
      maybeSendDocument();
    } catch (error) {
      failLaunch(error && error.message ? error.message : "編集用Markdownを準備できませんでした。");
    }
  };

  const receiveReturnDocument = async (message, source, session) => {
    const markdown = message.document && message.document.markdown;
    const filename = message.document && message.document.filename;
    const transactionId = typeof message.transactionId === "string" ? message.transactionId : "";
    if (!source || typeof markdown !== "string" || byteLength(markdown) > MAX_MARKDOWN_BYTES || !WRITE_ORIGIN) return;
    if (transactionId && processedReturnTransactions.has(transactionId)) {
      source.postMessage(returnEnvelope(session, { type: "tomos:return-ack", transactionId }), WRITE_ORIGIN);
      recordDiagnostic("return ack received");
      return;
    }
    if (transactionId && inflightReturnTransactions.has(transactionId)) return;

    const safeFilename = typeof filename === "string" && /^[^/\\]+\.(?:md|markdown|txt)$/i.test(filename)
      ? filename
      : "article.md";
    const importer = window.TomosPostImportMarkdown;
    if (typeof importer !== "function") {
      window.alert("編集済みMarkdownを投稿画面へ読み込めませんでした。Markdownを保存して手動で投稿してください。");
      return;
    }

    if (transactionId) inflightReturnTransactions.add(transactionId);
    let accepted = false;
    try {
      accepted = await Promise.resolve(importer(markdown, safeFilename, { transactionId, session }));
    } catch {
      accepted = false;
    }
    if (transactionId) inflightReturnTransactions.delete(transactionId);
    if (!accepted) return;
    if (transactionId) processedReturnTransactions.add(transactionId);
    source.postMessage(returnEnvelope(session, { type: "tomos:return-ack", transactionId }), WRITE_ORIGIN);
    recordDiagnostic("return ack received");
    history.replaceState(null, "", `${window.location.pathname}${window.location.search}`);
    document.getElementById("post-upload")?.scrollIntoView({ behavior: scrollBehavior, block: "start" });
  };

  document.querySelectorAll(".tomos-write-edit").forEach((button) => {
    button.addEventListener("click", () => void launchWrite(button));
  });

  window.addEventListener("message", (event) => {
    if (!WRITE_ORIGIN || event.origin !== WRITE_ORIGIN) return;
    if (!writeWindow || event.source !== writeWindow) return;
    const message = event.data;
    if (!message || typeof message !== "object") return;
    if (message.protocol !== PROTOCOL || message.session !== sessionId) return;
    if (typeof message.senderVersion === "string" && message.senderVersion !== "") writeVersion = message.senderVersion;

    if (message.type === "write:ready") {
      readyReceived = true;
      clearTimer(readyProbeTimer);
      readyProbeTimer = null;
      recordDiagnostic("ready received");
      maybeSendDocument();
      return;
    }

    if (message.type === "write:import-ack") {
      if (message.transactionId && message.transactionId !== documentTransactionId) return;
      clearTransportTimers();
      setButtonState("Tomos Writeで編集中", true);
      recordDiagnostic("import ack received", Math.max(0, documentAttempts - 1));
      return;
    }

    if (message.type === "write:return-probe") {
      event.source.postMessage({
        protocol: PROTOCOL,
        session: sessionId,
        senderVersion: TOMOS_VERSION,
        capabilities: CAPABILITIES,
        type: "tomos:return-ready",
      }, WRITE_ORIGIN);
      recordDiagnostic("return-ready sent");
      return;
    }

    if (message.type === "write:return-document") {
      void receiveReturnDocument(message, event.source, sessionId);
    }
  });

  const returnParams = new URLSearchParams(window.location.hash.slice(1));
  const returnSession = returnParams.get("tomosWriteReturn") === "1"
    ? returnParams.get("session") || ""
    : "";
  if (returnSession && document.getElementById("markdown_file") && WRITE_ORIGIN) {
    let returnSource = window.opener && !window.opener.closed ? window.opener : null;
    const sendReturnReady = (source) => {
      if (!source) return;
      source.postMessage({
        protocol: PROTOCOL,
        session: returnSession,
        senderVersion: TOMOS_VERSION,
        capabilities: CAPABILITIES,
        type: "tomos:return-ready",
      }, WRITE_ORIGIN);
      recordDiagnostic("return-ready sent");
    };

    const handleReturnMessage = (event) => {
      if (event.origin !== WRITE_ORIGIN || !event.source || event.source === window) return;
      const message = event.data;
      if (!message || typeof message !== "object") return;
      if (message.protocol !== PROTOCOL || message.session !== returnSession) return;
      if (typeof message.senderVersion === "string" && message.senderVersion !== "") writeVersion = message.senderVersion;
      if (!returnSource) returnSource = event.source;
      if (event.source !== returnSource) return;
      if (message.type === "write:return-probe") {
        sendReturnReady(event.source);
        return;
      }
      if (message.type === "write:return-document") {
        void receiveReturnDocument(message, event.source, returnSession);
      }
    };

    window.addEventListener("message", handleReturnMessage);
    sendReturnReady(returnSource);
  }
})();
