import { chromium } from 'playwright';
import { launchOptions } from './browser.mjs';
const [base, ...paths] = process.argv.slice(2);
const axePath = process.env.AXE_PATH ?? '/tmp/axe/node_modules/axe-core/axe.min.js';
const b = await chromium.launch(launchOptions());
for (const path of paths) {
  for (const [label, w, h, m] of [['390',390,844,true],['1440',1440,900,false]]) {
    const c = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: m?2:1, isMobile: m, hasTouch: m });
    const p = await c.newPage();
    await p.goto(base + path, { waitUntil: 'networkidle', timeout: 90000 });
    await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 100)); } window.scrollTo(0, 0); });
    await p.waitForTimeout(1200);
    await p.addScriptTag({ path: axePath });
    const res = await p.evaluate(async () => {
      const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
      return r.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, n: v.nodes.length, nodes: v.nodes.slice(0, 4).map(n => ({ t: n.target.join(' ').slice(0, 110), s: (n.failureSummary || '').split('\n').slice(1, 2).join(' ').slice(0, 170) })) }));
    });
    console.log(`\n## ${path} @${label}: ${res.length} rule(s)`);
    for (const v of res) { console.log(`- [${v.impact}] ${v.id} (${v.n}): ${v.help}`); for (const n of v.nodes) console.log(`    ${n.t} :: ${n.s}`); }
    await c.close();
  }
}
await b.close();
