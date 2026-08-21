import { readFileSync, statSync } from 'node:fs';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/app.tsx'];

if (!entry?.file) {
    throw new Error('Frontend entry was not found in the Vite manifest.');
}

const bytes = statSync(`public/build/${entry.file}`).size;
const budget = 400 * 1024;

if (bytes > budget) {
    throw new Error(`Frontend entry bundle is ${bytes} bytes; budget is ${budget} bytes.`);
}

console.log(`Frontend entry bundle: ${(bytes / 1024).toFixed(2)} kB / 400.00 kB budget.`);
