// Copies the Tabler icon font into the app and builds the name → character
// lookup the <TablerIcon> component uses.
//
// The website loads Tabler icons from a CDN (the "ti ti-heart" classes)
// and admins pick counselor icons by those names, so the app needs the
// same font. Keep the version in package.json matching the website's
// (search the PHP for "icons-webfont@"), then run:
//
//   node scripts/build-tabler-icons.mjs
import { copyFileSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pkgDir = dirname(createRequire(import.meta.url).resolve('@tabler/icons-webfont/package.json'));

const css = readFileSync(join(pkgDir, 'tabler-icons.css'), 'utf8');
const glyphs = {};
for (const [, name, hex] of css.matchAll(/\.ti-([a-z0-9-]+):before\s*\{\s*content:\s*"\\([0-9a-f]+)"/g)) {
  glyphs[name] = parseInt(hex, 16);
}

writeFileSync(join(here, '../src/icons/tabler-glyphs.json'), JSON.stringify(glyphs));
copyFileSync(join(pkgDir, 'fonts/tabler-icons.ttf'), join(here, '../assets/fonts/tabler-icons.ttf'));
copyFileSync(join(pkgDir, 'LICENSE'), join(here, '../assets/fonts/tabler-icons-LICENSE.txt'));
console.log(`${Object.keys(glyphs).length} icons written.`);
