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

writeFileSync(join(here, '../src/icons/tabler-glyphs.json'), JSON.stringify(keptGlyphs));
writeFileSync(join(here, '../assets/fonts/tabler-icons.ttf'), subsetBuffer);
writeFileSync(join(here, '../assets/fonts/tabler-icons-LICENSE.txt'), readFileSync(join(pkgDir, 'LICENSE')));

const total = Object.keys(allGlyphs).length;
const kept = Object.keys(keptGlyphs).length;
console.log(
  `${kept} of ${total} icons kept (${((100 * kept) / total).toFixed(0)}%). ` +
    `Font: ${(fullFontBuffer.length / 1024).toFixed(0)}KB -> ${(subsetBuffer.length / 1024).toFixed(0)}KB.`,
);
