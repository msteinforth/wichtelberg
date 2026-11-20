// Echter Browser-Test: Formulare abschicken (fängt z. B. Origin-/Referrer-Probleme, die Unit-Tests nicht sehen).
// Aufruf: BASE=http://127.0.0.1:8088 node tests/browser/forms.js   (benötigt Playwright)
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage();
  const email = `browsertest${Date.now()}@example.org`;
  await p.goto(`${BASE}/de`);
  await p.fill('input[name=email]', email);
  for (const [n, v] of [['first_name', 'Anna'], ['last_name', 'Test'], ['street', 'Hauptstraße'], ['house_number', '1'], ['postal_code', '50667'], ['city', 'Köln']]) {
    await p.fill(`input[name=${n}]`, v);
  }
  await p.check('input[name=adult]');
  await p.check('input[name=consent]');
  await p.click('form.card button');
  await p.waitForLoadState();
  const h1 = (await p.locator('h1').first().textContent()).trim();
  await b.close();
  if (h1 !== 'Fast geschafft!') {
    console.error(`FEHLER: Anmeldung ergab "${h1}"`);
    process.exit(1);
  }
  console.log('ok      Browser: Anmeldeformular absenden');
})();
