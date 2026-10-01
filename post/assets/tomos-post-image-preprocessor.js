(function (root, factory) {
  "use strict";
  const api = factory();
  if (typeof module !== "undefined" && module.exports) module.exports = api;
  if (root) root.TomosPostImagePreprocessor = api;
})(typeof window !== "undefined" ? window : null, function () {
  "use strict";

  const MAX_LONG_EDGE = 2048;
  const SUPPORTED_TYPES = new Set(["image/jpeg", "image/png", "image/webp"]);

  async function managedName(file) {
    if (!file || typeof file.name !== "string") return "";
    const match = file.name.toLowerCase().match(/\.([a-z0-9]+)$/);
    let extension = match ? match[1] : "";
    if (extension === "jpeg") extension = "jpg";
    if (!["jpg", "png", "webp", "gif"].includes(extension)) return "";
    const cryptoApi = typeof globalThis !== "undefined" ? globalThis.crypto : null;
    if (!cryptoApi || !cryptoApi.subtle || typeof file.arrayBuffer !== "function") return "";
    const digest = await cryptoApi.subtle.digest("SHA-256", await file.arrayBuffer());
    const prefix = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("").slice(0, 16);
    return prefix === "" ? "" : `tms-${prefix}.${extension}`;
  }

  function rewriteManagedReferences(markdown, renames) {
    let rewritten = String(markdown || "").replace(
      /(!\[[^\]\n]*\]\(images\/)(tms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp))(\))/giu,
      (whole, prefix, fileName, suffix) => `${prefix}${renames.get(fileName.toLowerCase()) || fileName}${suffix}`
    );
    const frontMatterEnd = rewritten.startsWith("---\n") ? rewritten.indexOf("\n---", 4) : -1;
    if (frontMatterEnd >= 0) {
      const frontMatter = rewritten.slice(0, frontMatterEnd + 1).replace(
        /^([ \t]*image[ \t]*:[ \t]*)(["']?)(images\/tms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp))\2([ \t]*)$/gim,
        (whole, prefix, quote, value, suffix) => {
          const replacement = renames.get(value.slice("images/".length).toLowerCase());
          return `${prefix}${quote}${replacement ? `images/${replacement}` : value}${quote}${suffix}`;
        }
      );
      rewritten = frontMatter + rewritten.slice(frontMatterEnd + 1);
    }
    return rewritten;
  }

  const unchanged = (file, reason, width = 0, height = 0, orientation = 1) => ({
    file,
    resized: false,
    reason,
    width,
    height,
    sourceWidth: width,
    sourceHeight: height,
    orientation,
  });

  async function jpegOrientation(file) {
    try {
      const bytes = new Uint8Array(await file.slice(0, 262144).arrayBuffer());
      const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
      if (bytes.length < 4 || bytes[0] !== 0xff || bytes[1] !== 0xd8) return 1;
      let offset = 2;
      while (offset + 4 <= bytes.length) {
        if (bytes[offset] !== 0xff) return 1;
        while (offset < bytes.length && bytes[offset] === 0xff) offset += 1;
        if (offset >= bytes.length) return 1;
        const marker = bytes[offset++];
        if (marker === 0xda || marker === 0xd9) return 1;
        if (offset + 2 > bytes.length) return 1;
        const segmentLength = view.getUint16(offset, false);
        if (segmentLength < 2 || offset + segmentLength > bytes.length) return 1;
        const segmentStart = offset + 2;
        if (
          marker === 0xe1 && segmentLength >= 14
          && bytes[segmentStart] === 0x45 && bytes[segmentStart + 1] === 0x78
          && bytes[segmentStart + 2] === 0x69 && bytes[segmentStart + 3] === 0x66
          && bytes[segmentStart + 4] === 0 && bytes[segmentStart + 5] === 0
        ) {
          const tiff = segmentStart + 6;
          const littleEndian = bytes[tiff] === 0x49 && bytes[tiff + 1] === 0x49;
          const bigEndian = bytes[tiff] === 0x4d && bytes[tiff + 1] === 0x4d;
          if (!littleEndian && !bigEndian) return 1;
          if (view.getUint16(tiff + 2, littleEndian) !== 42) return 1;
          const ifd = tiff + view.getUint32(tiff + 4, littleEndian);
          if (ifd + 2 > segmentStart + segmentLength - 2) return 1;
          const entries = view.getUint16(ifd, littleEndian);
          for (let index = 0; index < entries; index += 1) {
            const entry = ifd + 2 + index * 12;
            if (entry + 12 > segmentStart + segmentLength - 2) return 1;
            if (view.getUint16(entry, littleEndian) === 0x0112) {
              const value = view.getUint16(entry + 8, littleEndian);
              return value >= 1 && value <= 8 ? value : 1;
            }
          }
          return 1;
        }
        offset += segmentLength;
      }
    } catch (error) {
      return 1;
    }
    return 1;
  }

  async function prepare(file, maxBytes) {
    if (!file || !SUPPORTED_TYPES.has(String(file.type || "").toLowerCase())) {
      return unchanged(file, "format_not_processed");
    }
    if (typeof createImageBitmap !== "function" || typeof document === "undefined") {
      return unchanged(file, "browser_image_api_unavailable");
    }

    let bitmap = null;
    let canvas = null;
    const orientation = String(file.type || "").toLowerCase() === "image/jpeg"
      ? await jpegOrientation(file)
      : 1;
    try {
      // The default imageOrientation is from-image, so EXIF orientation is
      // applied before drawing and removed when the derivative is encoded.
      bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
      const sourceWidth = Number(bitmap.width) || 0;
      const sourceHeight = Number(bitmap.height) || 0;
      if (sourceWidth <= 0 || sourceHeight <= 0) {
        return unchanged(file, "image_dimensions_unavailable", 0, 0, orientation);
      }
      const longEdge = Math.max(sourceWidth, sourceHeight);
      if (longEdge <= MAX_LONG_EDGE) {
        return unchanged(file, "within_server_resize_limit", sourceWidth, sourceHeight, orientation);
      }

      const scale = MAX_LONG_EDGE / longEdge;
      const targetWidth = Math.max(1, Math.round(sourceWidth * scale));
      const targetHeight = Math.max(1, Math.round(sourceHeight * scale));
      canvas = document.createElement("canvas");
      canvas.width = targetWidth;
      canvas.height = targetHeight;
      const context = canvas.getContext("2d", { alpha: file.type.toLowerCase() === "image/png" });
      if (!context) return unchanged(file, "canvas_unavailable", sourceWidth, sourceHeight, orientation);
      context.drawImage(bitmap, 0, 0, targetWidth, targetHeight);

      const blob = await new Promise((resolve) => {
        canvas.toBlob(resolve, file.type, file.type.toLowerCase() === "image/jpeg" ? 0.86 : undefined);
      });
      if (!blob || blob.size <= 0) {
        return unchanged(file, "encode_failed", sourceWidth, sourceHeight, orientation);
      }
      if (String(blob.type || "").toLowerCase() !== String(file.type || "").toLowerCase()) {
        return unchanged(file, "browser_encoder_type_mismatch", sourceWidth, sourceHeight, orientation);
      }
      if (blob.size > maxBytes) {
        return unchanged(file, "derivative_exceeds_upload_limit", sourceWidth, sourceHeight, orientation);
      }

      const derivative = new File([blob], file.name, {
        type: blob.type,
        lastModified: file.lastModified,
      });
      return {
        file: derivative,
        resized: true,
        reason: "resized_in_browser",
        width: targetWidth,
        height: targetHeight,
        sourceBytes: file.size,
        outputBytes: derivative.size,
        sourceWidth,
        sourceHeight,
        orientation,
      };
    } catch (error) {
      return unchanged(file, "browser_decode_or_resize_failed", 0, 0, orientation);
    } finally {
      if (bitmap && typeof bitmap.close === "function") bitmap.close();
      if (canvas) {
        canvas.width = 0;
        canvas.height = 0;
      }
    }
  }

  return { prepare, managedName, rewriteManagedReferences, maxLongEdge: MAX_LONG_EDGE };
});
