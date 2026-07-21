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
    widget_found: false,
    button_found: false,
    resolved_kaspi_url: null,
    artifact_dir: null,
    current_step: null,
    page_url: null,
    http_status: null,
    timeout: false,
    captcha: false,
    exception_class: null,
    status: 'error',
    error: null,
    duration_ms: Date.now() - started,
    ...payload,
  }));
}

function canonicalKaspiUrl(rawUrl) {
  if (!rawUrl || !String(rawUrl).includes('kaspi.kz/shop/p/')) return null;

  try {
    const parsed = new URL(String(rawUrl));
    parsed.search = '';
    parsed.hash = '';
    let value = parsed.toString();
    if (!value.endsWith('/')) value += '/';

    return value;
  } catch {
    return String(rawUrl);
  }
}

async function saveArtifacts(page, artifactDir, reason, consoleLines) {
  if (!artifactDir) return;

  try {
    await fs.mkdir(artifactDir, { recursive: true });
    await fs.writeFile(path.join(artifactDir, 'reason.txt'), reason || 'unknown', 'utf8');
    await fs.writeFile(path.join(artifactDir, 'console.log'), consoleLines.join('\n'), 'utf8');
    await fs.writeFile(path.join(artifactDir, 'page.html'), await page.content(), 'utf8');
    await page.screenshot({ path: path.join(artifactDir, 'screenshot.png'), fullPage: true }).catch(() => {});
  } catch {
    // Diagnostics must never make the resolver fail harder.
  }
}

async function collectUrlsFromPage(page) {
  const urls = await page.evaluate(() => {
    const found = [];
    document.querySelectorAll('a[href], iframe[src]').forEach((node) => {
      const value = node.getAttribute('href') || node.getAttribute('src');
      if (value && value.includes('kaspi.kz/shop/p/')) found.push(value);
    });

    const html = document.documentElement.innerHTML;
    const matches = html.match(/https?:\/\/kaspi\.kz\/shop\/p\/[^"' <>)\\]+/gi) || [];
    found.push(...matches);

    return found;
  }).catch(() => []);

  for (const frame of page.frames()) {
    try {
      const frameUrl = canonicalKaspiUrl(frame.url());
      if (frameUrl) urls.push(frameUrl);

      const frameUrls = await frame.evaluate(() => {
        const found = [];
        document.querySelectorAll('a[href]').forEach((node) => {
          const value = node.getAttribute('href');
          if (value && value.includes('kaspi.kz/shop/p/')) found.push(value);
        });

        return found;
      }).catch(() => []);
      urls.push(...frameUrls);
    } catch {
      // Cross-origin frames are expected.
    }
  }

  return urls.map(canonicalKaspiUrl).filter(Boolean);
}

async function firstKaspiUrl(context, page) {
  const pageUrl = canonicalKaspiUrl(page.url());
  if (pageUrl) return pageUrl;

  const urls = await collectUrlsFromPage(page);
  if (urls.length) return urls[0];

  for (const candidate of context.pages()) {
    const url = canonicalKaspiUrl(candidate.url());
    if (url) return url;
  }

  return null;
}

async function waitForKaspiReady(page, delayMs) {
  // The .ks-widget container is static HTML — it should always be present after domcontentloaded.
  // We look for it with a short timeout first; if absent, the product truly has no Kaspi button.
  const widget = page.locator('div.ks-widget').first();
  const widgetFound = await widget.waitFor({ state: 'attached', timeout: 10000 })
    .then(() => true)
    .catch(() => false);

  if (!widgetFound) return { widgetFound: false, jsLoaded: false };

  // Trigger reinit if the initializer is already available.
  await page.evaluate(() => {
    if (window.ksWidgetInitializer && typeof window.ksWidgetInitializer.reinit === 'function') {
      window.ksWidgetInitializer.reinit();
    }
  }).catch(() => {});

  // Wait for the external Kaspi JS to load.
  const jsLoaded = await page.waitForFunction(() => {
    return Array.from(document.scripts).some((script) => script.src.includes('ks-wi_ext.js'))
      || Boolean(window.ksWidgetInitializer);
  }, null, { timeout: 20000 }).then(() => true).catch(() => false);

  // After JS loads, wait for the widget to actually render a button/link with a Kaspi URL.
  // This is the key pause: without it we scan the DOM before the widget has injected its href.
  const renderedLink = await page.waitForFunction(() => {
    return document.querySelector('.ks-widget a[href*="kaspi.kz/shop/p/"]') !== null
      || document.querySelector('a[href*="kaspi.kz/shop/p/"]') !== null;
  }, null, { timeout: Math.max(delayMs, 15000) }).then(() => true).catch(() => false);

  if (!renderedLink && delayMs > 0) {
    // Fallback: honour the caller's explicit delay so slow CDNs have more time.
    await page.waitForTimeout(delayMs);
  }

  return { widgetFound, jsLoaded };
}

async function clickLocatorAndWait(locator, context, page) {
  const popupPromise = context.waitForEvent('page', { timeout: 12000 }).catch(() => null);
  const navigationPromise = page.waitForURL(/kaspi\.kz\/shop\/p\//i, { timeout: 12000 }).catch(() => null);

  await locator.click({ timeout: 6000, force: true });

  const popup = await popupPromise;
  if (popup) {
    await popup.waitForLoadState('domcontentloaded', { timeout: 20000 }).catch(() => {});
    return popup;
  }

  await navigationPromise;

  return page;
}

async function clickWidget(page, context) {
  const locators = [
    page.locator('a[href*="kaspi.kz/shop/p/"]').first(),
    page.locator('.ks-widget a[href], .ks-widget button, .ks-widget [role="button"]').first(),
    page.getByText(/kaspi|купить|рассроч/i).first(),
  ];

  for (const locator of locators) {
    try {
      if (await locator.count()) {
        const target = await clickLocatorAndWait(locator, context, page);
        const url = await firstKaspiUrl(context, target);
        if (url) return { page: target, buttonFound: true, url };
      }
    } catch {
      // Try next locator.
    }
  }

  const widget = page.locator('div.ks-widget').first();
  try {
    const box = await widget.boundingBox({ timeout: 5000 });
    if (box) {
      const popupPromise = context.waitForEvent('page', { timeout: 12000 }).catch(() => null);
      await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
      const popup = await popupPromise;
      const target = popup || page;
      await target.waitForLoadState('domcontentloaded', { timeout: 20000 }).catch(() => {});
      const url = await firstKaspiUrl(context, target);
      if (url) return { page: target, buttonFound: true, url };

      return { page: target, buttonFound: true, url: null };
    }
  } catch {
    // No visible widget box.
  }

  return { page, buttonFound: false, url: null };
}

const url = arg('url');
const headless = boolArg('headless', false);
const delayMs = Number.parseInt(arg('delay-ms', '5000'), 10);
const timeoutMs = Number.parseInt(arg('timeout-ms', '60000'), 10);
const artifactDir = arg('artifact-dir');
const consoleLines = [];
let currentStep = 'init';
let lastHttpStatus = null;

if (!url) {
  output({ status: 'error', error: 'Missing --url.', artifact_dir: artifactDir, current_step: currentStep });
  process.exit(1);
}

let browser;
let page;

try {
  browser = await chromium.launch({ headless });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    locale: 'ru-RU',
  });

  page = await context.newPage();
  page.on('console', (message) => consoleLines.push(`[${message.type()}] ${message.text()}`));
  page.on('pageerror', (error) => consoleLines.push(`[pageerror] ${error.message}`));
  page.on('response', (response) => {
    if (response.url() === url) lastHttpStatus = response.status();
  });

  currentStep = 'open_storefront_product_page';
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

  currentStep = 'wait_for_kaspi_widget';
  const { widgetFound, jsLoaded } = await waitForKaspiReady(page, delayMs);
  if (!widgetFound) {
    await saveArtifacts(page, artifactDir, 'Widget not found', consoleLines);
    output({ status: 'widget_not_found', widget_found: false, error: 'Widget not found', artifact_dir: artifactDir, current_step: currentStep, page_url: page.url(), http_status: lastHttpStatus, captcha: await hasCaptcha(page) });
    process.exit(0);
  }

  if (!jsLoaded) {
    await saveArtifacts(page, artifactDir, 'Kaspi JS not loaded', consoleLines);
    output({ status: 'kaspi_js_not_loaded', widget_found: true, error: 'Kaspi JS not loaded', artifact_dir: artifactDir, current_step: currentStep, page_url: page.url(), http_status: lastHttpStatus, captcha: await hasCaptcha(page) });
    process.exit(0);
  }

  currentStep = 'scan_existing_kaspi_url';
  const directUrl = await firstKaspiUrl(context, page);
  if (directUrl) {
    output({
      status: 'resolved_from_widget',
      widget_found: true,
      button_found: true,
      resolved_kaspi_url: directUrl,
      artifact_dir: artifactDir,
      current_step: currentStep,
      page_url: page.url(),
      http_status: lastHttpStatus,
    });
    process.exit(0);
  }

  currentStep = 'click_kaspi_widget';
  const clicked = await clickWidget(page, context);
  if (!clicked.buttonFound) {
    await saveArtifacts(page, artifactDir, 'Button not found', consoleLines);
    output({ status: 'kaspi_button_not_found', widget_found: true, button_found: false, error: 'Button not found', artifact_dir: artifactDir, current_step: currentStep, page_url: page.url(), http_status: lastHttpStatus, captcha: await hasCaptcha(page) });
    process.exit(0);
  }

  if (clicked.url) {
    output({
      status: 'resolved_from_widget',
      widget_found: true,
      button_found: true,
      resolved_kaspi_url: clicked.url,
      artifact_dir: artifactDir,
      current_step: currentStep,
      page_url: clicked.page.url(),
      http_status: lastHttpStatus,
    });
    process.exit(0);
  }

  await saveArtifacts(clicked.page, artifactDir, 'URL not received', consoleLines);
  output({ status: 'kaspi_url_not_opened', widget_found: true, button_found: true, error: 'URL not received', artifact_dir: artifactDir, current_step: currentStep, page_url: clicked.page.url(), http_status: lastHttpStatus, captcha: await hasCaptcha(clicked.page) });
} catch (error) {
  const message = error instanceof Error ? error.message : String(error);
  const status = message.toLowerCase().includes('timeout') ? 'widget_timeout' : 'error';
  if (page) await saveArtifacts(page, artifactDir, message, consoleLines);
  output({ status, error: message, artifact_dir: artifactDir, current_step: currentStep, page_url: page ? page.url() : null, http_status: lastHttpStatus, timeout: status === 'widget_timeout', exception_class: error instanceof Error ? error.name : null, captcha: page ? await hasCaptcha(page) : false });
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
