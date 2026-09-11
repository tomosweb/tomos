(() => {
  "use strict";

  const PROTOCOL = "tomos-write-handoff/v1";
  const WRITE_ORIGIN = "https://tomoswords.org";
  const WRITE_URL = `${WRITE_ORIGIN}/write/`;
  const MAX_MARKDOWN_BYTES = 2 * 1024 * 1024;
  const HANDSHAKE_TIMEOUT_MS = 10000;

  let writeWindow = null;
  let sessionId = "";
  let selectedButton = null;
  let pendingDocument = null;
  let readyReceived = false;
  let handshakeTimer = null;

  const byteLength = (value) => new TextEncoder().encode(value).byteLength;

  const randomSessionId = () => {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  };

  const clearHandshakeTimer = () => {
    if (handshakeTimer !== null) {
      window.clearTimeout(handshakeTimer);
      handshakeTimer = null;
    }
  };

  const setButtonState = (label, disabled) => {
    if (!selectedButton) return;
    selectedButton.textContent = label;
    selectedButton.disabled = disabled;
  };

  const failLaunch = (message) => {
    clearHandshakeTimer();
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

  const maybeSendDocument = () => {
    if (!readyReceived || !pendingDocument || !writeWindow || writeWindow.closed) return;
    writeWindow.postMessage({
      protocol: PROTOCOL,
      session: sessionId,
      type: "tomos:document",
      document: pendingDocument,
    }, WRITE_ORIGIN);
  };

  const launchWrite = async (button) => {
    const article = button.closest(".editable-result");
    const downloadForm = article && article.querySelector('form input[name="action"][value="download_published_markdown"]')?.form;
    const tokenInput = downloadForm && downloadForm.querySelector('input[name="_token"]');
    const contentPathInput = downloadForm && downloadForm.querySelector('input[name="content_path"]');
    const returnUrl = safeReturnUrl(button.dataset.returnUrl || "");
    if (!tokenInput || !contentPathInput || !returnUrl) {
      window.alert("Tomos Writeへ渡す原稿情報を確認できませんでした。一覧を再読み込みしてください。");
      return;
    }

    const approved = window.confirm(
      "この記事のMarkdownをTomos公式サイトのTomos Writeへ渡します。\n\n編集内容は自動では公開されません。Tomosへ戻した後に更新内容を確認できます。"
    );
    if (!approved) return;

    sessionId = randomSessionId();
    selectedButton = button;
    pendingDocument = null;
    readyReceived = false;
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

    handshakeTimer = window.setTimeout(() => {
      failLaunch("Tomos Writeとの接続を確認できませんでした。");
    }, HANDSHAKE_TIMEOUT_MS);

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

  const receiveReturnDocument = (message, source, session) => {
    const fileInput = document.getElementById("markdown_file");
    if (!fileInput || !source) return;

    const markdown = message.document && message.document.markdown;
    const filename = message.document && message.document.filename;
    if (typeof markdown !== "string" || byteLength(markdown) > MAX_MARKDOWN_BYTES) return;
    if (typeof DataTransfer === "undefined") {
      window.alert("このブラウザでは編集済みMarkdownを投稿画面へ渡せません。Write側でMarkdownを保存してください。");
      return;
    }

    const safeFilename = typeof filename === "string" && /^[^/\\]+\.(?:md|markdown|txt)$/i.test(filename)
      ? filename
      : "article.md";
    const transfer = new DataTransfer();
    transfer.items.add(new File([markdown], safeFilename, { type: "text/markdown", lastModified: Date.now() }));
    fileInput.files = transfer.files;
    fileInput.dispatchEvent(new Event("change", { bubbles: true }));

    source.postMessage({
      protocol: PROTOCOL,
      session,
      type: "tomos:return-ack",
    }, WRITE_ORIGIN);
    history.replaceState(null, "", `${window.location.pathname}${window.location.search}`);
    document.getElementById("post-upload")?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  document.querySelectorAll(".tomos-write-edit").forEach((button) => {
    button.addEventListener("click", () => void launchWrite(button));
  });

  window.addEventListener("message", (event) => {
    if (event.origin !== WRITE_ORIGIN) return;
    if (!writeWindow || event.source !== writeWindow) return;
    const message = event.data;
    if (!message || typeof message !== "object") return;
    if (message.protocol !== PROTOCOL || message.session !== sessionId) return;

    if (message.type === "write:ready") {
      readyReceived = true;
      maybeSendDocument();
      return;
    }

    if (message.type === "write:return-probe") {
      event.source.postMessage({
        protocol: PROTOCOL,
        session: sessionId,
        type: "tomos:return-ready",
      }, WRITE_ORIGIN);
      return;
    }

    if (message.type === "write:return-document") {
      receiveReturnDocument(message, event.source, sessionId);
      return;
    }

    if (message.type === "write:import-ack") {
      clearHandshakeTimer();
      setButtonState("Tomos Writeで編集中", true);
    }
  });

  const returnParams = new URLSearchParams(window.location.hash.slice(1));
  const returnSession = returnParams.get("tomosWriteReturn") === "1"
    ? returnParams.get("session") || ""
    : "";
  if (returnSession && document.getElementById("markdown_file")) {
    const opener = window.opener;
    let returnSource = opener && !opener.closed ? opener : null;

    const handleReturnMessage = (event) => {
      if (event.origin !== WRITE_ORIGIN || !event.source || event.source === window) return;
      const message = event.data;
      if (!message || typeof message !== "object") return;
      if (message.protocol !== PROTOCOL || message.session !== returnSession) return;
      if (!returnSource) returnSource = event.source;
      if (event.source !== returnSource) return;

      if (message.type === "write:return-probe") {
        event.source.postMessage({
          protocol: PROTOCOL,
          session: returnSession,
          type: "tomos:return-ready",
        }, WRITE_ORIGIN);
        return;
      }

      if (message.type === "write:return-document") {
        receiveReturnDocument(message, event.source, returnSession);
      }
    };

    window.addEventListener("message", handleReturnMessage);
    if (returnSource) {
      returnSource.postMessage({
        protocol: PROTOCOL,
        session: returnSession,
        type: "tomos:return-ready",
      }, WRITE_ORIGIN);
    }
  }
})();
