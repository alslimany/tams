---
source: Official Spatie Browsershot docs
library: Spatie Browsershot
package: spatie/browsershot
topic: Node, Puppeteer, Chrome/Chromium requirements for laravel-pdf Browsershot driver
tech_stack: Laravel 12 with spatie/laravel-pdf
fetched: 2026-05-11T00:00:00Z
official_docs: https://spatie.be/docs/browsershot/v4/requirements
---

# Browsershot v4 requirements relevant to spatie/laravel-pdf

Browsershot v4 requires:

- Node 22.0 LTS or higher.
- Puppeteer v23.0 or higher.
- Chrome/Chromium available to Puppeteer or explicitly configured.

Mac/project install:

```bash
npm install puppeteer
```

Global install option:

```bash
npm install puppeteer --location=global
```

Forge Ubuntu 24.04 example from docs:

```bash
node -v && npm -v
sudo npm install -g puppeteer
npx puppeteer browsers install chrome
sudo apt update
sudo apt install libx11-xcb1 libxcomposite1 libasound2t64 libatk1.0-0 libatk-bridge2.0-0 libcairo2 libcups2 libdbus-1-3 libexpat1 libfontconfig1 libgbm1 libgcc1 libglib2.0-0 libgtk-3-0 libnspr4 libpango-1.0-0 libpangocairo-1.0-0 libstdc++6 libx11-6 libx11-xcb1 libxcb1 libxcomposite1 libxcursor1 libxdamage1 libxext6 libxfixes3 libxi6 libxrandr2 libxrender1 libxss1 libxtst6
```

For Ubuntu 22.04, replace `libasound2t64` with `libasound2`.

Direct Browsershot configuration methods include:

```php
Browsershot::html('Foo')
    ->setNodeBinary('/usr/local/bin/node')
    ->setNpmBinary('/usr/local/bin/npm')
    ->setIncludePath('$PATH:/usr/local/bin')
    ->setNodeModulePath('/path/to/project/node_modules/')
    ->setChromePath('/path/to/chrome');
```

In Laravel PDF, prefer setting these through `config/laravel-pdf.php` / `.env`:

```env
LARAVEL_PDF_NODE_BINARY=/usr/local/bin/node
LARAVEL_PDF_NPM_BINARY=/usr/local/bin/npm
LARAVEL_PDF_INCLUDE_PATH=/usr/local/bin
LARAVEL_PDF_CHROME_PATH=/path/to/chrome
LARAVEL_PDF_NODE_MODULES_PATH=/path/to/project/node_modules
LARAVEL_PDF_NO_SANDBOX=true
```
