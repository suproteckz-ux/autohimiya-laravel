import fs from 'node:fs/promises';
import path from 'node:path';

const started = Date.now();

function arg(name, fallback = null) {
  const prefix = `--${name}=`;
  const match = process.argv.find((entry) => entry.startsWith(prefix));
  return match ? match.slice(prefix.length) : fallback;
}

function boolArg(name, fallback = false) {
  const value = arg(name);
  if (value === null) return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function output(payload) {
  const status = payload.status || 'failed';
  const url = payload.url || null;
  const ok = payload.ok ?? (status === 'resolved' && Boolean(url));
  const reason = payload.reason ?? (ok ? null : (status === 'not_found' ? 'not_found' : 'browser_error'));

  process.stdout.write(JSON.stringify({
    ok,
    sku,
    method: ok ? 'search' : (reason === 'invalid_input' ? null : 'search'),
    reason,
    status: 'failed',
    url: null,
    error: null,
    duration_ms: Date.now() - started,
    ...payload,
  }));
}

function canonicalKaspiUrl(rawUrl) {
  if (!rawUrl || !String(rawUrl).includes('kaspi.kz/shop/p/')) return null;
  try {
    const parsed = new URL(String(rawUrl), 'https://kaspi.kz');
    parsed.search = '';
    parsed.hash = '';
    let value = parsed.toString();
    if (!value.endsWith('/')) value += '/';
    return value;
  } catch {
    return null;
  }
}

const sku = arg('sku');
const name = arg('name', '');
const headless = boolArg('headless', true);
const debug = boolArg('debug', false);
const artifactDir = arg('artifact-dir');

if (!sku) {
  output({ ok: false, reason: 'invalid_input', method: null, status: 'failed', error: 'Missing --sku.' });
  process.exit(1);
}

let browser;
let page;

try {
  if (artifactDir) await fs.mkdir(artifactDir, { recursive: true });
  const { chromium } = await import('playwright');
  browser = await chromium.launch({ headless });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    locale: 'ru-RU',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
  });
  page = await context.newPage();
  const searchText = name ? `${sku} ${name}` : sku;
  await page.goto(`https://kaspi.kz/shop/search/?text=${encodeURIComponent(searchText)}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

  const urls = await page.evaluate(() => {
    const found = [];
    document.querySelectorAll('a[href*="/shop/p/"], a[href*="kaspi.kz/shop/p/"]').forEach((node) => {
      const href = node.getAttribute('href');
      const text = node.textContent || '';
      if (href) found.push({ href, text });
    });
    const html = document.documentElement.innerHTML;
    found.push(...(html.match(/https?:\/\/kaspi\.kz\/shop\/p\/[^"' <>)\\]+/gi) || []).map((href) => ({ href, text: '' })));
    return found;
  });

  const skuKey = String(sku).toLowerCase().replace(/[^a-z0-9]+/gi, '');
  const url = urls
    .map((candidate) => ({ url: canonicalKaspiUrl(candidate.href), text: candidate.text || '' }))
    .filter((candidate) => candidate.url)
    .find((candidate) => {
      const haystack = `${candidate.url} ${candidate.text}`.toLowerCase().replace(/[^a-z0-9]+/gi, '');
      return skuKey && haystack.includes(skuKey);
    })?.url || null;
  if (artifactDir) {
    await fs.writeFile(path.join(artifactDir, 'search.html'), await page.content(), 'utf8').catch(() => {});
  }

  output(url ? { ok: true, status: 'resolved', url, reason: null } : { ok: false, status: 'not_found', reason: 'not_found', error: 'Kaspi search did not expose an exact SKU product URL.' });
} catch (error) {
  if (debug && error instanceof Error && error.stack) {
    process.stderr.write(error.stack + '\n');
  }
  output({ ok: false, reason: 'browser_error', error: error instanceof Error ? error.message : String(error) });
  process.exitCode = 1;
} finally {
  if (browser) await browser.close().catch(() => {});
}
