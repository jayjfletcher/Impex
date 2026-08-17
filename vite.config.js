import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The dashboard is compiled into public/ and published with the
// `impex-assets` tag, so a host application never needs a build step.
export default defineConfig({
    plugins: [vue()],
    // The output directory is also the published asset directory, so there is
    // no separate public folder to copy from.
    publicDir: false,
    build: {
        outDir: 'public',
        emptyOutDir: false,
        lib: {
            entry: 'resources/js/app.js',
            formats: ['iife'],
            name: 'Impex',
            fileName: () => 'app.js',
        },
        cssCodeSplit: false,
        rollupOptions: {
            output: { assetFileNames: 'app.[ext]' },
        },
    },
})
