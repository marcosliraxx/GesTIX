/* Caminho: gestores/servidor/Arcoll.WinUI3/HybridReact/vite.config.ts */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: path.resolve(__dirname, '../Assets/hybrid/dashboard'),
    emptyOutDir: true,
    sourcemap: false,
  },
  server: { host: '127.0.0.1' }
});
