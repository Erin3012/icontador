'use strict';

// Prueba local aislada: valida que Playwright pueda arrancar Chromium headless en Linux.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent('<!doctype html><title>Agente SII Linux</title><main>Chromium listo</main>');
    assert.equal(await page.title(), 'Agente SII Linux');
    assert.equal(await page.locator('main').innerText(), 'Chromium listo');
    console.log(`Playwright y Chromium headless funcionan en Linux (${browser.version()}).`);
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
