"use strict";

const assert = require("node:assert/strict");
const { prepare, managedName, rewriteManagedReferences } = require("../post/assets/tomos-post-image-preprocessor.js");

function jpegWithOrientation(orientation, size) {
  const exifPayload = Buffer.concat([
    Buffer.from("Exif\0\0"),
    Buffer.from([0x49, 0x49, 42, 0, 8, 0, 0, 0, 1, 0, 0x12, 0x01, 3, 0, 1, 0, 0, 0, orientation, 0, 0, 0, 0, 0, 0, 0]),
  ]);
  const segmentLength = exifPayload.length + 2;
  const header = Buffer.from([0xff, 0xd8, 0xff, 0xe1, (segmentLength >> 8) & 0xff, segmentLength & 0xff]);
  return Buffer.concat([header, exifPayload, Buffer.alloc(Math.max(0, size - header.length - exifPayload.length))]);
}

async function run() {
  const originalCreateImageBitmap = global.createImageBitmap;
  const originalDocument = global.document;
  const imageCanvas = { width: 0, height: 0 };
  let decodedDimensions = [5712, 4284];
  imageCanvas.getContext = () => ({ drawImage() {} });
  imageCanvas.toBlob = (callback, type) => callback(new Blob([Buffer.alloc(200000)], { type }));

  global.createImageBitmap = async () => ({ width: decodedDimensions[0], height: decodedDimensions[1], close() {} });
  global.document = { createElement: (tag) => tag === "canvas" ? imageCanvas : null };
  try {
    const source = new File([Buffer.alloc(3432571)], "IMG_6089(2).jpeg", { type: "image/jpeg" });
    const originalManagedName = await managedName(source);
    assert.match(originalManagedName, /^tms-[a-f0-9]{16}\.jpg$/);
    const result = await prepare(source, 10 * 1024 * 1024);
    assert.equal(result.resized, true, "large source should be resized in the browser");
    assert.equal(result.reason, "resized_in_browser");
    assert.equal(imageCanvas.width, 0, "canvas should be released after encoding");
    assert.equal(imageCanvas.height, 0, "canvas should be released after encoding");
    assert.equal(result.width, 2048);
    assert.equal(result.height, 1536);
    assert.equal(result.sourceWidth, 5712);
    assert.equal(result.sourceHeight, 4284);
    assert.equal(result.file.name, source.name, "the original filename must be retained");
    assert.equal(result.file.type, source.type, "the image type must be retained");
    assert.ok(result.file.size < source.size, "the derivative should be smaller than the source fixture");
    const derivativeManagedName = await managedName(result.file);
    assert.notEqual(derivativeManagedName, originalManagedName, "a resized derivative must receive its own content hash name");
    const derivativeHash = Buffer.from(await crypto.subtle.digest("SHA-256", await result.file.arrayBuffer())).toString("hex");
    assert.equal(derivativeManagedName, `tms-${derivativeHash.slice(0, 16)}.jpg`, "the upload name must match the bytes checked by the server");
    const markdown = `---\nimage: images/${originalManagedName}\n---\n![fixture](images/${originalManagedName})\n`;
    assert.equal(
      rewriteManagedReferences(markdown, new Map([[originalManagedName, derivativeManagedName]])),
      `---\nimage: images/${derivativeManagedName}\n---\n![fixture](images/${derivativeManagedName})\n`,
      "front matter and article references must point at the content-hashed derivative"
    );

    decodedDimensions = [4284, 5712]; // createImageBitmap dimensions after EXIF orientation=6.
    const orientedSource = new File([jpegWithOrientation(6, 3432571)], source.name, { type: "image/jpeg" });
    const oriented = await prepare(orientedSource, 10 * 1024 * 1024);
    assert.equal(oriented.width, 1536);
    assert.equal(oriented.height, 2048);
    assert.equal(oriented.orientation, 6, "the source EXIF orientation should be diagnosed");

    const tooSmallCap = await prepare(source, 1024);
    assert.equal(tooSmallCap.resized, false);
    assert.equal(tooSmallCap.reason, "derivative_exceeds_upload_limit");
  } finally {
    global.createImageBitmap = originalCreateImageBitmap;
    global.document = originalDocument;
  }

  const gif = new File([Buffer.alloc(20)], "animation.gif", { type: "image/gif" });
  const unchangedGif = await prepare(gif, 10 * 1024 * 1024);
  assert.equal(unchangedGif.file, gif, "GIF animation must not be flattened");
  assert.equal(unchangedGif.reason, "format_not_processed");

  console.log("post_image_preprocessor_check: OK");
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
