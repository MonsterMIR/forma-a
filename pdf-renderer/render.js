/**
 * Раунд 201. Рендерит готовый самодостаточный HTML (подписи/QR уже
 * инлайнены как data:-URI вызывающим кодом — ActPdfService::generate())
 * в PDF через headless Chromium (Puppeteer) — тот же движок, что печатает
 * страницу, когда пользователь сам жмёт "Печать" в браузере. Заменяет
 * dompdf как основной механизм генерации PDF для вложений в письмах:
 * dompdf не является настоящим браузером и у него накопился целый ряд
 * собственных особенностей (раунды 195/199/200), не имеющих отношения к
 * самой вёрстке — тогда как headless Chromium воспроизводит именно то,
 * что видно по кнопке "Печать", без точечных обходов под конкретный движок.
 *
 * Вызывается из PHP (ActPdfService::generateViaChromium()) как:
 *   node render.js <входной .html> <выходной .pdf>
 * Код возврата 0 + непустой выходной файл = успех. Любая ошибка — код
 * возврата 1, подробности в stderr; вызывающий код просто откатывается
 * на dompdf как на запасной вариант.
 */
const fs = require('fs');
const puppeteer = require('puppeteer');

(async () => {
  const [, , htmlPath, outPath] = process.argv;
  if (!htmlPath || !outPath) {
    console.error('usage: node render.js <input.html> <output.pdf>');
    process.exit(2);
  }

  const html = fs.readFileSync(htmlPath, 'utf8');

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  try {
    const page = await browser.newPage();
    // Активируем именно print-правила CSS (@media print в print.php) —
    // то же самое, что браузер делает сам при реальной печати/"Сохранить
    // как PDF", и ровно то, что раньше (до раунда 199) не включалось
    // в dompdf по умолчанию.
    await page.emulateMediaType('print');
    await page.setContent(html, { waitUntil: 'networkidle0' });
    await page.pdf({
      path: outPath,
      printBackground: true,   // сохраняем цветные плашки шапки/заголовков таблиц
      preferCSSPageSize: true, // уважаем @page { size: A4 portrait; margin: 6mm 7mm; } из print.php
    });
  } finally {
    await browser.close();
  }
})().catch((err) => {
  console.error('RENDER_ERROR:', err && err.stack ? err.stack : err);
  process.exit(1);
});
