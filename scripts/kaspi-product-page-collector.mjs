import { chromium } from 'playwright';
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
  process.stdout.write(JSON.stringify({
    status: 'error',
    final_url: null,
    http_status: null,
    html_path: null,
    captcha: false,
    error: null,
    duration_ms: Date.now() - started,
    ...payload,
  }));
}

const url = arg('url');
const headless = boolArg('headless', true);
const artifactDir = arg('artifact-dir') || process.cwd();
const timeoutMs = Number.parseInt(arg('timeout-ms', '60000'), 10);

if (!url) {
  output({ error: 'Missing --url.' });
  process.exit(1);
}

let browser;
let page;
let lastStatus = null;

try {
  await fs.mkdir(artifactDir, { recursive: true });
  browser = await chromium.launch({ headless });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    locale: 'ru-RU',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
  });
  page = await context.newPage();
  page.on('response', (response) => {
    if (response.url() === url || response.url() === page.url()) lastStatus = response.status();
  });

  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  lastStatus = response ? response.status() : lastStatus;
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

  const html = await page.content();
  const htmlPath = path.join(artifactDir, 'kaspi-product-page.html');
  await fs.writeFile(htmlPath, html, 'utf8');

  output({
    status: 'ok',
    final_url: page.url(),
    http_status: lastStatus,
    html_path: htmlPath,
    captcha: await hasCaptcha(page),
  });
} catch (error) {
  output({
    status: 'failed',
    final_url: page ? page.url() : null,
    http_status: lastStatus,
    captcha: page ? await hasCaptcha(page) : false,
    error: error instanceof Error ? error.message : String(error),
  });
  process.exitCode = 1;
} finally {
  if (browser) await browser.close().catch(() => {});
}

async function hasCaptcha(page) {
  return page.evaluate(() => {
    const text = document.body?.innerText?.toLowerCase() || '';
    return text.includes('captcha') || text.includes('капча') || text.includes('robot') || text.includes('робот');
  }).catch(() => false);
}
