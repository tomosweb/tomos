import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { File } from 'node:buffer';
import { webcrypto } from 'node:crypto';
import { createRequire } from 'node:module';
const { rewriteManagedReferences } = createRequire(import.meta.url)('../post/assets/tomos-post-image-preprocessor.js');

const php = fs.readFileSync(new URL('../post/index.php', import.meta.url), 'utf8');
const start = php.indexOf('(() => {\n  const scrollBehavior');
let script = php.slice(start, php.indexOf('</script>', start));
script = script.replace(/\}\)\(\);\s*$/, `window.test = {
  load(markdown) { submitting = false; resetImportedMarkdownState(); loadedMarkdown = markdown;
    loadedMarkdownFilename = 'article.md'; importedFilename = 'article.md';
    requiredImages = extractImages(markdown); frontMatterImage = extractFrontMatterLocalImage(markdown);
    renderImageMatches(); renderOgpImage(); },
  wait: () => imageSelectionTask,
  state: () => ({ requiredImages, frontMatterImage, selectedImages, managedImageRenames }),
}; })();`);
class Element {
  constructor() { this.listeners = {}; this.files = []; this.value = ''; this.style = {}; this.dataset = {}; this.children = []; }
  addEventListener(type, fn) { this.listeners[type] = fn; }
  querySelectorAll() { return []; }
  querySelector() { return null; }
  appendChild(el) { this.children.push(el); }
  click() { this.clicked = true; }
  scrollIntoView() {}
  submit() { this.submitted = true; }
}
const elements = new Map();
const get = id => { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); };
const requests = [];
const window = { matchMedia: () => ({matches:false}), crypto: webcrypto, addEventListener() {} };
vm.runInNewContext(script, { window, document: { body: {dataset:{}}, getElementById: get, createElement: () => new Element() },
  File, FileReader: class {}, FormData, Uint8Array, Map, Set, Promise, navigator: {},
  fetch: async (url, options) => { requests.push({url, body:options?.body}); return {ok:true, json:async () => ({ok:true, upload_session_id:'session', capabilities:{effective_image_max_bytes:1048576}})}; }
});
// No DataTransfer exists in this environment.
const api = window.test;
const picker = get('post-upload-form').children[0];
async function choose(target, file) {
  const status = get(target === 'ogp' ? 'ogp-image-status' : 'image-match-status');
  status.listeners.click({target:{closest:()=>({dataset:{imageTarget:String(target)}})}});
  assert.equal(picker.clicked, true);
  picker.files = file ? [file] : [];
  picker.listeners.change();
  await api.wait();
}
const photo = (name, bytes) => new File([bytes], name, {type:'image/jpeg'});
api.load('---\nimage: original.png\n---\n![A](original.png)\n![B](other.png)');
await choose(0, photo('image.jpeg', 'first-photo'));
let state = api.state();
assert.ok(state.requiredImages[0].fileName);
assert.equal(state.requiredImages[0].fileName, state.frontMatterImage.fileName, 'shared OGP/article path binds together');
assert.equal(state.requiredImages[1].fileName, '', 'other reference is never guessed');
await choose(1, photo('image.jpeg', 'second-photo'));
assert.equal(api.state().selectedImages.size, 2, 'same filename, different bytes retain both selections');
await choose(0, null);
assert.equal(api.state().selectedImages.size, 2, 'cancel preserves selections');
await choose(0, photo('renamed.jpg', 'replacement'));
assert.equal(api.state().selectedImages.size, 2, 'replaced unreferenced file is removed');
await get('post-upload-form').listeners.submit({preventDefault(){}});
const uploaded = requests.filter(r=>r.url === '?post_api=image');
assert.equal(uploaded.length, 2);
assert.match(get('tomos-handoff-markdown').value, /image: images\/tms-[a-f0-9]{16}\.jpg/);
assert.equal((get('tomos-handoff-markdown').value.match(/!\[[AB]\]\(images\/tms-/g)||[]).length, 2);
assert.equal(get('post-upload-form').submitted, true);
// Managed references also allow explicit replacement after photo conversion changes bytes.
window.TomosPostImagePreprocessor = { rewriteManagedReferences };
api.load('![A](images/tms-aaaaaaaaaaaaaaaa.jpg)');
await choose(0, photo('converted.jpeg','changed-encoding'));
state = api.state();
assert.notEqual(state.requiredImages[0].fileName, 'tms-aaaaaaaaaaaaaaaa.jpg');
assert.equal(state.managedImageRenames.get('tms-aaaaaaaaaaaaaaaa.jpg'), state.requiredImages[0].fileName);
await get('post-upload-form').listeners.submit({preventDefault(){}});
assert.equal(get('tomos-handoff-markdown').value, `![A](images/${state.requiredImages[0].fileName})`);
// Ordinary matching remains available without manual binding.
api.load('![A](matching.jpg)');
get('image_files').value = 'C:\\fakepath\\matching.jpg';
get('image_files').files = [photo('matching.jpg','normal')];
get('image_files').listeners.change();
await api.wait();
assert.equal(api.state().selectedImages.size, 1);
assert.equal(get('image_files').value, 'C:\\fakepath\\matching.jpg', 'native selection label must not be cleared');
assert.match(get('image-processing-status').textContent, /1点選択/);
// A new import invalidates a pending picker and queued selection.
api.load('![A](old.jpg)');
const status = get('image-match-status');
status.listeners.click({target:{closest:()=>({dataset:{imageTarget:'0'}})}});
api.load('![B](new.jpg)');
picker.files = [photo('old.jpg','old')];
picker.listeners.change();
await api.wait();
assert.equal(api.state().selectedImages.size, 0);
// Rejected selections must never report successful matching, even without references.
api.load('本文だけの原稿');
get('image_files').files = [photo('extra.jpg', 'extra')];
get('image_files').listeners.change();
await api.wait();
assert.equal(api.state().selectedImages.size, 0);
assert.match(get('image-processing-status').textContent, /対応先がMarkdownに見つかりません/);
assert.equal(get('image-processing-status').style.color, 'var(--tomos-danger-text)');
await get('post-upload-form').listeners.submit({preventDefault(){}});
assert.equal(get('image_files').disabled, true, 'unmatched native files must not be submitted');
assert.equal(picker.disabled, true);
api.load('![A](expected.jpg)');
get('image_files').files = [photo('renamed.jpg', 'renamed')];
get('image_files').listeners.change();
await api.wait();
assert.match(get('image-processing-status').textContent, /この画像を選ぶ/);
// Tomos Write angle-bracket destinations (the reported IMG_6286.jpeg case).
api.load('---\nimage: IMG_6286.jpeg\n---\n![IMG_6286](<IMG_6286.jpeg>)');
assert.equal(api.state().requiredImages.length, 1);
assert.equal(api.state().requiredImages[0].sourceName, 'IMG_6286.jpeg');
await choose(0, photo('image.jpeg', 'iphone-photo'));
await get('post-upload-form').listeners.submit({preventDefault(){}});
assert.equal(api.state().frontMatterImage.fileName, api.state().requiredImages[0].fileName);
assert.equal(get('tomos-handoff-markdown').value, `---\nimage: images/${api.state().frontMatterImage.fileName}\n---\n![IMG_6286](images/${api.state().requiredImages[0].fileName})`);
assert.ok(requests.filter(r=>r.url === '?post_api=image').at(-1).body.get('image_file'));
// Angle destinations may contain spaces or parentheses; preserve the reference key.
api.load('![Photo](<photos/my photo (1).jpeg>)');
assert.equal(api.state().requiredImages.length, 1);
await choose(0, photo('image.jpeg', 'space-path-photo'));
await get('post-upload-form').listeners.submit({preventDefault(){}});
assert.equal(get('tomos-handoff-markdown').value, `![Photo](images/${api.state().requiredImages[0].fileName})`);
api.load('![A](<images/tms-aaaaaaaaaaaaaaaa.jpg>)');
assert.equal(api.state().requiredImages[0].kind, 'managed');
await choose(0, photo('converted.jpeg', 'managed-angle-photo'));
await get('post-upload-form').listeners.submit({preventDefault(){}});
assert.equal(get('tomos-handoff-markdown').value, `![A](images/${api.state().requiredImages[0].fileName})`);
api.load('![remote](<https://example.test/photo.jpeg>)');
assert.equal(api.state().requiredImages.length, 0, 'external angle destinations must not require uploads');
console.log('post_image_selection_runtime_check: OK');
