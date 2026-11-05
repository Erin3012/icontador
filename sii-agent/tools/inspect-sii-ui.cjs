const { chromium } = require('playwright');

const targets = [
  { name: 'Portal SII', url: 'https://homer.sii.cl/' },
  { name: 'Consulta RCV', url: 'https://www4.sii.cl/consdcvinternetui/#/index' },
];

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultNavigationTimeout(30000);

  for (const target of targets) {
    console.log(`\n## ${target.name}`);
    try {
      await page.goto(target.url, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const snapshot = await page.evaluate(() => {
        const visible = (el) => {
          const r = el.getBoundingClientRect();
          const s = getComputedStyle(el);
          return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
        };
        const clean = (s) => (s || '').replace(/\s+/g, ' ').trim().slice(0, 180);
        return {
          title: document.title,
          url: location.href.split('#')[0] + (location.hash ? '#(ruta visible)' : ''),
          headings: [...document.querySelectorAll('h1,h2,h3')].filter(visible).map(e => clean(e.innerText)).filter(Boolean).slice(0, 20),
          buttons: [...document.querySelectorAll('button,input[type=button],input[type=submit]')].filter(visible).map(e => clean(e.innerText || e.value || e.getAttribute('aria-label'))).filter(Boolean).slice(0, 40),
          links: [...document.querySelectorAll('a[href]')].filter(visible).map(e => ({ text: clean(e.innerText), href: clean(e.innerText) ? e.href.split('#')[0] : '' })).filter(x => x.text).slice(0, 50),
          fields: [...document.querySelectorAll('input,select,textarea')].filter(visible).map(e => ({ tag: e.tagName.toLowerCase(), type: e.type || '', label: clean(e.labels && [...e.labels].map(l => l.innerText).join(' ')), placeholder: clean(e.getAttribute('placeholder')), name: e.getAttribute('name') || '' })).slice(0, 30),
          visibleText: clean(document.body.innerText).slice(0, 5000),
        };
      });
      console.log(JSON.stringify(snapshot, null, 2));
    } catch (error) {
      console.log(JSON.stringify({ error: String(error).split('\n')[0] }));
    }
  }
  await context.close();
  await browser.close();
})().catch((error) => {
  console.error(String(error).split('\n')[0]);
  process.exitCode = 1;
});
