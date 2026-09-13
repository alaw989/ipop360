import { chromium } from 'playwright';
import { launchOptions } from './browser.mjs';
const [base, out] = process.argv.slice(2);
const b = await chromium.launch(launchOptions());
for (const [label, w, h, m] of [['390',390,844,true],['1440',1440,900,false]]) {
  const c = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: m?2:1, isMobile: m, hasTouch: m });
  const p = await c.newPage();
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0,140)));
  let docLoads = 0; p.on('request', r => { if (r.resourceType() === 'document') docLoads++; });
  await p.goto(base + '/search?city=Austin&state=TX', { waitUntil: 'networkidle', timeout: 90000 });
  await p.evaluate(() => window.scrollTo(0, 900)); await p.waitForTimeout(600);
  const scrolledTo = await p.evaluate(() => window.scrollY);
  const card = p.locator('article h2 a').nth(3);
  const name = (await card.innerText()).trim();
  if (!m) { await card.hover(); await p.waitForTimeout(400); }
  await card.click();
  await p.waitForURL(/\/restaurants\//, { timeout: 30000 }); await p.waitForLoadState('networkidle');
  const back = p.locator('[data-testid="back-link"]');
  const backText = (await back.innerText()).trim(); const backHref = await back.getAttribute('href');
  await p.screenshot({ path: `${out}/nav-show-${label}.jpg`, type: 'jpeg', quality: 75 });
  await back.click();
  await p.waitForURL(/\/search/, { timeout: 30000 }); await p.waitForTimeout(1200);
  const after = await p.evaluate(() => ({ url: location.pathname + location.search, y: window.scrollY }));
  console.log(label, JSON.stringify({ name, docLoads, backText, backHref, scrolledTo, after, errs }));
  // Manifest reachable and valid
  if (label === '390') { const r = await p.request.get(base + '/manifest.json'); console.log('manifest', r.status(), r.headers()['content-type'], (await r.json()).display); }
  await c.close();
}
await b.close();
