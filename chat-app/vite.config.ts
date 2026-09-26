import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds to a fixed-name JS + CSS pair (no content hash in the filename)
// so inc/kounselia-chat-engine.php can keep reading them by a known path,
// the same way it reads the hand-written assets today.
export default defineConfig({
  plugins: [react()],
  // @kounselia/core lives outside this folder (packages/core, shared with
  // the mobile app); let the dev server read it.
  server: { fs: { allow: ['.', '../packages/core'] } },
  build: {
    outDir: 'dist',
    // Keeps the CSS as its own kounselia-chat.css (which the PHP loader
    // inlines) instead of IIFE mode injecting it from the JS.
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        // The bundle is inlined as a classic <script>, so without a wrapper
        // its minified top-level names become globals — a stray `var C`
        // once overwrote window.C (the counselor list) and broke talk.php.
        format: 'iife',
        entryFileNames: 'kounselia-chat.js',
        assetFileNames: (asset) =>
          asset.name?.endsWith('.css') ? 'kounselia-chat.css' : 'assets/[name][extname]',
      },
    },
  },
});
