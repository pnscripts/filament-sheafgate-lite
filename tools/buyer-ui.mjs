// Drives the Quick start resource in headless Chrome: an XLSX workbook (sheet choice, header row,
// mapping, import with failed rows) and a Windows-1252 semicolon CSV. SHOTS=<dir> saves screenshots.
// Needs Playwright (resolved from PLAYWRIGHT_DIR, default ~/.cache/pn-mobile-qa) and Google Chrome.
import { createRequire } from 'module';
import os from 'os';
import fs from 'fs';
const require = createRequire((process.env.PLAYWRIGHT_DIR || `${os.homedir()}/.cache/pn-mobile-qa`) + '/');
const { chromium } = require('playwright');
const base = process.env.BASE;
const shots = process.env.SHOTS || '';
const profile = process.env.CHROME_PROFILE || `${os.tmpdir()}/sheafgate-lite-chrome`;
fs.rmSync(profile, { recursive: true, force: true });
const ctx = await chromium.launchPersistentContext(profile, {
  channel: 'chrome', headless: true, viewport: { width: 1600, height: 900 }, deviceScaleFactor: Number(process.env.DSF || 1),
  args: ['--headless=new', '--mute-audio', '--disable-speech-api', '--no-first-run'],
});
const p = ctx.pages()[0] || await ctx.newPage();
const problems = [];
p.on('console', m => { if (['error', 'warning'].includes(m.type())) problems.push(m.text()); });
p.on('pageerror', e => problems.push(String(e)));
const check = (ok, what) => { console.log(`  ${ok ? 'ok' : 'FAIL'}: ${what}`); if (!ok) process.exitCode = 1; };
const shot = async (name) => { if (shots) { await p.waitForTimeout(500); await p.screenshot({ path: `${shots}/${name}.png` }); } };
const settle = async (ms = 1200) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const text = async (loc) => ((await loc.innerText().catch(() => '')) || '').replace(/\s+/g, ' ');

const openModal = async () => {
  await p.getByRole('button', { name: 'Import products' }).click();
  const modal = p.locator('.fi-modal-window').filter({ hasText: 'Import products' });
  await modal.waitFor();
  return modal;
};

await p.goto(`${base}/demo-login`, { waitUntil: 'networkidle' });
await p.evaluate(() => localStorage.setItem('theme', 'light'));
await p.reload({ waitUntil: 'networkidle' });

// 1. Workbook: sheet, header row below a title, mapping with examples
let modal = await openModal();
check(/Upload an XLSX, ODS or CSV file/.test(await text(modal)), 'upload field asks for XLSX, ODS or CSV');
await modal.locator('input[type=file]').setInputFiles(process.env.DEMO_FILE);
await modal.getByText('Sheet “Spring update”, column names in row 3.').waitFor({ timeout: 20000 });
await settle(1500);
const m = await text(modal);
check(/Sheet/.test(m) && /Header row/.test(m), 'sheet and header row fields shown');
check(/SKU \(e\.g\. PN-1040\)/.test(m), 'mapping shows example values');
await shot('modal');

// Dark mode of the same modal
await p.evaluate(() => localStorage.setItem('theme', 'dark'));
await p.evaluate(() => document.documentElement.classList.add('dark'));
await p.waitForTimeout(600);
await shot('dark');
await p.evaluate(() => { localStorage.setItem('theme', 'light'); document.documentElement.classList.remove('dark'); });
await p.waitForTimeout(400);

await modal.getByRole('button', { name: 'Import', exact: true }).click();
await settle(2500);
const toast = await text(p.locator('.fi-no-notification').first());
check(/Import completed/.test(toast) && /failed to import/.test(toast), `Filament's completion notification: ${toast}`);
check(await p.getByRole('link', { name: /Download information about the failed rows?/ }).count() > 0, "Filament's failed-rows download offered");
await shot('result');

// 2. Windows-1252 CSV with semicolons
await p.goto(`${base}/admin/products`, { waitUntil: 'networkidle' });
modal = await openModal();
await modal.locator('input[type=file]').setInputFiles(process.env.DEMO_CSV);
await modal.getByText(/Read as Windows-1252 text, columns separated by semicolons/).waitFor({ timeout: 20000 });
await settle(1200);
check(/Name \(e\.g\. Crème Linen Apron\)/.test(await text(modal)), 'accented text read correctly');
await shot('csv');
await modal.getByRole('button', { name: 'Import', exact: true }).click();
await settle(2500);
await p.goto(`${base}/admin/products?tableSearch=PN-11`, { waitUntil: 'networkidle' });
check(/Crème Linen Apron/.test(await text(p.locator('main'))), 'CSV rows in the products table');

check(problems.length === 0, `console errors/warnings: ${problems.length}${problems.length ? ' ' + problems[0] : ''}`);
await ctx.close();
