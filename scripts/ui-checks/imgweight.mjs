import { chromium } from 'playwright';
import { launchOptions } from './browser.mjs';
const [base, path = '/'] = process.argv.slice(2);
const b = await chromium.launch(launchOptions());
for (const [label, w, h, m] of [['390',390,844,true],['1440',1440,900,false]]) {
  const c = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: m?2:1, isMobile: m, hasTouch: m });
  const p = await c.newPage();
  const rows = [];
  p.on('response', async r => { const t = r.request().resourceType(); if (!['image','script','stylesheet','font','document','fetch','xhr'].includes(t)) return; try { const len = (await r.body()).length; rows.push({ t, kb: Math.round(len/1024), u: r.url().replace(/^https?:\/\//,'').slice(0,110) }); } catch {} });
  await p.goto(base + path, { waitUntil: 'networkidle', timeout: 90000 });
  await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 150)); } });
  await p.waitForTimeout(2500);
  const byType = {}; for (const r of rows) byType[r.t] = (byType[r.t] ?? 0) + r.kb;
  console.log(label, JSON.stringify(byType));
  rows.filter(r => r.t === 'image').sort((a,b) => b.kb - a.kb).slice(0, 12).forEach(r => console.log('  ', r.kb, 'KB', r.u));
  await c.close();
}
await b.close();
