// Copies the Tabler icon font into the app and builds the name → character
// lookup the <TablerIcon> component uses — but only for the icons the app
// can actually need, not the whole 4,900-icon set. The full font is 2.1MB;
// most apps that use Tabler Icons only ever draw a few dozen of them, and
// shipping every glyph nobody will ever see is exactly the kind of bulk a
// "don't make the download bigger than it needs to be" pass should remove.
//
// The website loads Tabler icons from a CDN (the "ti ti-heart" classes)
// and admins can type ANY Tabler icon name into the Counselor Studio's
// icon field, so the app can't know in advance every icon a counselor
// might ever use. To stay safe without shipping the whole font, the kept
// set is the union of:
//
//   1. every icon name the app's own code actually asks for (scanned from
//      source, so a new `<TablerIcon name="...">` is picked up next time
//      this script runs — nobody has to remember to update a list);
//   2. every icon name the website ships as a *default* (the built-in
//      counselors and mood options), scanned from the PHP source the same
//      way, for the same reason;
//   3. a generous, hand-picked list of icon names from categories an
//      admin choosing a *new* counselor's icon would plausibly reach for
//      (feelings, health, people, nature, home, trust, hobbies, work — see
//      CURATED_KEYWORDS below).
//
// An icon name that isn't in that union still works — TablerIcon already
// falls back to a plain speech-bubble ("message-circle") for any name it
// doesn't recognise (see components/TablerIcon.tsx) — it just won't show
// the specific icon inside the app until this script's keep-list is
// widened to include it and it's rebuilt. The website itself is
// unaffected either way, since it always loads the full font from a CDN.
//
// Keep the @tabler/icons-webfont version in package.json matching the
// website's (search the PHP for "icons-webfont@"), then run:
//
//   node scripts/build-tabler-icons.mjs
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import subsetFont from 'subset-font';

const here = dirname(fileURLToPath(import.meta.url));
const mobileDir = join(here, '..');
const repoDir = join(mobileDir, '..');
const require = createRequire(import.meta.url);
const pkgDir = dirname(require.resolve('@tabler/icons-webfont/package.json'));

// ---- 1. Every icon this version of the font actually has ------------------

const css = readFileSync(join(pkgDir, 'tabler-icons.css'), 'utf8');
/** @type {Record<string, number>} name -> codepoint */
const allGlyphs = {};
for (const [, name, hex] of css.matchAll(/\.ti-([a-z0-9-]+):before\s*\{\s*content:\s*"\\([0-9a-f]+)"/g)) {
  allGlyphs[name] = parseInt(hex, 16);
}

// ---- 2. Icon names the app's own code and the website's defaults use ------

/** Every .ts/.tsx file under a directory, recursively. */
function tsFilesUnder(dir) {
  const out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...tsFilesUnder(full));
    else if (/\.(ts|tsx)$/.test(entry.name)) out.push(full);
  }
  return out;
}

const usedInApp = new Set();
for (const file of tsFilesUnder(join(mobileDir, 'src'))) {
  const src = readFileSync(file, 'utf8');
  for (const m of src.matchAll(/<TablerIcon\b[^>]*?\bname=\{?["'`]([a-z0-9-]+)["'`]\}?/gs)) usedInApp.add(m[1]);
  for (const m of src.matchAll(/\bicon=\{?["'`]([a-z0-9-]+)["'`]\}?/gs)) usedInApp.add(m[1]);
  for (const m of src.matchAll(/\bicon:\s*["'`]([a-z0-9-]+)["'`]/gs)) usedInApp.add(m[1]);
  // Any other quoted word that happens to be an icon name, so names kept in
  // lookup tables or picked in code (the tab bar's { settings: 'settings' },
  // isOpen ? 'chevron-up' : 'chevron-down') are never missed. This also
  // keeps a few ordinary words that are icon names too ("link", "ghost");
  // a handful of unused glyphs is a fair price for never losing a real one.
  for (const m of src.matchAll(/["'`]([a-z0-9]+(?:-[a-z0-9]+)*)["'`]/g)) {
    if (m[1] in allGlyphs) usedInApp.add(m[1]);
  }
}

// The website's own defaults (built-in counselors, mood options): scanned
// from the mu-plugin PHP so a new default there is picked up automatically.
const usedByDefault = new Set();
const phpDir = join(repoDir, 'portal/wp-content/mu-plugins/kounselia/includes');
try {
  for (const entry of readdirSync(phpDir)) {
    if (!entry.endsWith('.php')) continue;
    const src = readFileSync(join(phpDir, entry), 'utf8');
    for (const m of src.matchAll(/'icon'\s*=>\s*'ti-([a-z0-9-]+)'/g)) usedByDefault.add(m[1]);
  }
} catch {
  console.warn(`Couldn't scan ${phpDir} for default icons — is the mobile app still inside the kounselia repo? Continuing without it.`);
}

// ---- 3. A generous curated set for icons an admin might pick later --------

// Whole hyphen-separated words, not substrings (so "ear" matches the icon
// literally named "ear", never the unrelated "bear" or "wear-off").
const CURATED_KEYWORDS = new Set(
  `mood brain heart emoji face mask
   pulse stethoscope pill vaccine bandage aid health medical hospital
   ambulance yoga meditation massage thermometer ear eye tooth teeth bone
   lungs sos
   user users friend baby man woman child kid family couple
   leaf flower tree plant sun moon star stars cloud rain snow wind rainbow
   mountain water wave seedling flame candle sunset sunrise
   home sofa bed bath coffee cup tea book books reading notebook music
   headphones headphone blanket armchair
   message messages chat mail phone send bell comment comments
   speakerphone microphone mic
   clock alarm calendar hourglass
   shield lock key lifebuoy check certificate award badge badges medal
   trophy trust
   walk walking run running bike biking hiking swimming swim ball activity
   stretching
   star sparkle sparkles gift balloon confetti cake gem crown compass map
   target flag puzzle infinity thumb
   battery droplet fire snowflake
   briefcase building school graduation cap`
    .split(/\s+/)
    .filter(Boolean),
);

const curated = new Set();
for (const name of Object.keys(allGlyphs)) {
  const tokens = name.split('-');
  if (tokens.some((t) => CURATED_KEYWORDS.has(t))) curated.add(name);
}

// ---- Put it together, subset the font, write the outputs ------------------

const keep = new Set(['message-circle', ...usedInApp, ...usedByDefault, ...curated]);
/** @type {Record<string, number>} */
const keptGlyphs = {};
const unknown = [];
for (const name of keep) {
  if (name in allGlyphs) keptGlyphs[name] = allGlyphs[name];
  else unknown.push(name); // e.g. a typo'd icon name somewhere in the app
}
if (unknown.length) {
  console.warn(`${unknown.length} icon name(s) aren't in this font version, so they were skipped: ${unknown.join(', ')}`);
}

const fullFontBuffer = readFileSync(join(pkgDir, 'fonts/tabler-icons.ttf'));
const text = Object.values(keptGlyphs)
  .map((cp) => String.fromCodePoint(cp))
  .join('');
const subsetBuffer = await subsetFont(fullFontBuffer, text, {
  targetFormat: 'truetype',
  // The upstream font's GSUB (ligature) table isn't valid, and icons are
  // never drawn through ligature substitution anyway — only by codepoint —
  // so there's nothing in it this app needs.
  dropTables: ['GSUB'],
  keepFeatures: [],
  noLayoutClosure: true,
});

// ---- Fix the font's height numbers --------------------------------------
//
// One icon in the upstream font ("frustum-off") is drawn nearly four times
// taller than the rest, and the font's overall height numbers (the "head"
// bounding box and the OS/2 "win" ascent) were set from it. Android sizes
// every piece of text in a font from those numbers, so each icon got a box
// ~3.7x too tall with the icon at the bottom: icons fell out of small
// boxes (and vanished), sat under button labels instead of beside them,
// and pushed the tab bar labels down. The subsetter keeps those numbers
// as they were, so they're reset here to the font's own normal line: 900
// above the baseline and 100 below (1000 in all, the font's em size),
// which every kept icon fits inside (checked below).

/** Reads a TrueType table directory: tag -> { offset, length, record }. */
function tableDirectory(buf) {
  const tables = {};
  const count = buf.readUInt16BE(4);
  for (let i = 0; i < count; i++) {
    const record = 12 + i * 16;
    tables[buf.toString('latin1', record, record + 4)] = { record, offset: buf.readUInt32BE(record + 8), length: buf.readUInt32BE(record + 12) };
  }
  return tables;
}

function checksum(buf, offset, length) {
  let sum = 0;
  for (let i = 0; i < length; i += 4) {
    const word = Buffer.alloc(4);
    buf.copy(word, 0, offset + i, Math.min(offset + i + 4, offset + length));
    sum = (sum + word.readUInt32BE(0)) >>> 0;
  }
  return sum;
}

function fixMetrics(buf, ascent, descent) {
  const out = Buffer.from(buf);
  const t = tableDirectory(out);
  const head = t.head.offset;
  const os2 = t['OS/2'].offset;

  // Every glyph must fit inside the new line, or it would be clipped.
  const loca = t.loca.offset;
  const longLoca = out.readInt16BE(head + 50) === 1;
  const glyphCount = out.readUInt16BE(t.maxp.offset + 4);
  let yMin = 0;
  let yMax = 0;
  for (let g = 0; g < glyphCount; g++) {
    const start = longLoca ? out.readUInt32BE(loca + g * 4) : out.readUInt16BE(loca + g * 2) * 2;
    const end = longLoca ? out.readUInt32BE(loca + (g + 1) * 4) : out.readUInt16BE(loca + (g + 1) * 2) * 2;
    if (end === start) continue; // empty glyph
    const glyph = t.glyf.offset + start;
    yMin = Math.min(yMin, out.readInt16BE(glyph + 4));
    yMax = Math.max(yMax, out.readInt16BE(glyph + 8));
  }
  if (yMax > ascent || -yMin > descent) {
    throw new Error(`A kept icon (${yMin}..${yMax}) doesn't fit the ${-descent}..${ascent} line; widen it in this script.`);
  }

  out.writeInt16BE(-descent, head + 38); // head.yMin
  out.writeInt16BE(ascent, head + 42); // head.yMax
  out.writeUInt16BE(ascent, os2 + 74); // OS/2.usWinAscent
  out.writeUInt16BE(descent, os2 + 76); // OS/2.usWinDescent

  // Recompute the checksums the changed tables carry.
  out.writeUInt32BE(0, head + 8); // head.checkSumAdjustment, zero while summing
  for (const tag of ['head', 'OS/2']) {
    out.writeUInt32BE(checksum(out, t[tag].offset, t[tag].length), t[tag].record + 4);
  }
  out.writeUInt32BE((0xb1b0afba - checksum(out, 0, out.length)) >>> 0, head + 8);
  return { font: out, yMin, yMax };
}

const fixed = fixMetrics(subsetBuffer, 900, 100);

writeFileSync(join(here, '../src/icons/tabler-glyphs.json'), JSON.stringify(keptGlyphs));
writeFileSync(join(here, '../assets/fonts/tabler-icons.ttf'), fixed.font);
writeFileSync(join(here, '../assets/fonts/tabler-icons-LICENSE.txt'), readFileSync(join(pkgDir, 'LICENSE')));

const total = Object.keys(allGlyphs).length;
const kept = Object.keys(keptGlyphs).length;
console.log(
  `${kept} of ${total} icons kept (${((100 * kept) / total).toFixed(0)}%). ` +
    `Font: ${(fullFontBuffer.length / 1024).toFixed(0)}KB -> ${(fixed.font.length / 1024).toFixed(0)}KB. ` +
    `Icons span ${fixed.yMin}..${fixed.yMax} on a -100..900 line.`,
);
