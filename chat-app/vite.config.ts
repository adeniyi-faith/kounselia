import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds to a fixed-name JS + CSS pair (no content hash in the filename)
// so inc/kounselia-chat-engine.php can keep reading them by a known path,
// the same way it reads the hand-written assets today.
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'kounselia-chat.js',
        assetFileNames: (asset) =>
          asset.name?.endsWith('.css') ? 'kounselia-chat.css' : 'assets/[name][extname]',
      },
    },
  },
});
