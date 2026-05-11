---
source: Context7 API + official Spatie docs
library: Spatie Laravel PDF
package: spatie/laravel-pdf
topic: Laravel 12 installation, Blade PDF generation, inline responses, APIs, Browsershot requirements, testing
tech_stack: Laravel 12
fetched: 2026-05-11T00:00:00Z
official_docs: https://spatie.be/docs/laravel-pdf/v2
---

# Spatie Laravel PDF v2 — Laravel 12 implementation notes

## Requirements

- `spatie/laravel-pdf` v2 requires PHP 8.2+ and Laravel 11+, so it supports Laravel 12.
- Default driver: `browsershot`.
- Browsershot requires `spatie/browsershot`, Node.js 22.0 LTS or higher, Puppeteer v23.0 or higher, and a Chrome/Chromium binary.
- Alternative drivers include Cloudflare, Gotenberg, WeasyPrint, DOMPDF, and Chrome.

## Installation

```bash
composer require spatie/laravel-pdf
composer require spatie/browsershot
npm install puppeteer
```

Optional config publishing:

```bash
php artisan vendor:publish --tag=pdf-config
```

Use `.env` to choose/configure the driver:

```env
LARAVEL_PDF_DRIVER=browsershot
LARAVEL_PDF_NODE_BINARY=/usr/local/bin/node
LARAVEL_PDF_NPM_BINARY=/usr/local/bin/npm
LARAVEL_PDF_INCLUDE_PATH=/usr/local/bin
LARAVEL_PDF_CHROME_PATH=/path/to/chrome-or-chromium
LARAVEL_PDF_NODE_MODULES_PATH=/path/to/node_modules
LARAVEL_PDF_TEMP_PATH=/tmp
LARAVEL_PDF_NO_SANDBOX=true
```

Published config shape:

```php
return [
    'driver' => env('LARAVEL_PDF_DRIVER', 'browsershot'),

    'browsershot' => [
        'node_binary' => env('LARAVEL_PDF_NODE_BINARY'),
        'npm_binary' => env('LARAVEL_PDF_NPM_BINARY'),
        'include_path' => env('LARAVEL_PDF_INCLUDE_PATH'),
        'chrome_path' => env('LARAVEL_PDF_CHROME_PATH'),
        'node_modules_path' => env('LARAVEL_PDF_NODE_MODULES_PATH'),
        'bin_path' => env('LARAVEL_PDF_BIN_PATH'),
        'temp_path' => env('LARAVEL_PDF_TEMP_PATH'),
        'write_options_to_file' => env('LARAVEL_PDF_WRITE_OPTIONS_TO_FILE', false),
        'no_sandbox' => env('LARAVEL_PDF_NO_SANDBOX', false),
    ],
];
```

## Generate a PDF from a Blade view

Facade:

```php
use Spatie\LaravelPdf\Facades\Pdf;

Pdf::view('pdf.invoice', ['invoice' => $invoice])
    ->save(storage_path('app/invoices/invoice.pdf'));
```

Helper:

```php
use function Spatie\LaravelPdf\Support\pdf;

pdf('pdf.invoice', ['invoice' => $invoice])
    ->save(storage_path('app/invoices/invoice.pdf'));
```

From raw HTML:

```php
use Spatie\LaravelPdf\Facades\Pdf;

Pdf::html('<h1>Hello world</h1>')
    ->save(storage_path('app/example.pdf'));
```

## Return inline in browser

PDF responses are inline by default. Always set a filename with `name()`.

```php
namespace App\Http\Controllers;

use App\Models\Invoice;
use function Spatie\LaravelPdf\Support\pdf;

class ShowInvoicePdfController
{
    public function __invoke(Invoice $invoice)
    {
        return pdf()
            ->view('pdf.invoice', compact('invoice'))
            ->name("invoice-{$invoice->id}.pdf");
    }
}
```

Force download instead:

```php
return pdf()
    ->view('pdf.invoice', compact('invoice'))
    ->name("invoice-{$invoice->id}.pdf")
    ->download();
```

## Common API examples

```php
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Enums\Orientation;
use Spatie\LaravelPdf\Enums\Unit;
use Spatie\LaravelPdf\Facades\Pdf;

Pdf::view('pdf.report', ['report' => $report])
    ->format(Format::A4)
    ->landscape()
    ->margins(20, 15, 20, 15, Unit::Millimeter)
    ->save(storage_path('app/reports/report.pdf'));

Pdf::view('pdf.receipt', ['order' => $order])
    ->paperSize(80, 200, 'mm')
    ->orientation(Orientation::Portrait)
    ->save(storage_path('app/receipts/receipt.pdf'));

Pdf::view('pdf.contract', ['contract' => $contract])
    ->headerView('pdf.header', ['company' => $company])
    ->footerHtml('<div style="font-size: 10px;">Page @pageNumber of @totalPages</div>')
    ->save(storage_path('app/contracts/contract.pdf'));

$base64 = Pdf::view('pdf.invoice', ['invoice' => $invoice])->base64();
```

Conditional chain helpers are supported:

```php
Pdf::view('pdf.invoice', ['invoice' => $invoice])
    ->format('a4')
    ->when($invoice->isLandscape(), fn ($pdf) => $pdf->landscape())
    ->unless($invoice->isCompact(), fn ($pdf) => $pdf->margins(20, 15, 20, 15))
    ->save(storage_path('app/invoices/invoice.pdf'));
```

## Test-friendly usage

Use `Pdf::fake()` so tests do not launch a browser or generate actual PDFs.

```php
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

beforeEach(function () {
    Pdf::fake();
});

it('returns the invoice PDF inline', function () {
    $invoice = Invoice::factory()->create();

    $this->get(route('invoices.pdf', $invoice))->assertOk();

    Pdf::assertRespondedWithPdf(function (PdfBuilder $pdf) use ($invoice) {
        return $pdf->downloadName === "invoice-{$invoice->id}.pdf"
            && ! $pdf->isDownload()
            && str_contains($pdf->html, (string) $invoice->id);
    });
});
```

Other assertions:

```php
Pdf::assertViewIs('pdf.invoice');
Pdf::assertSee('Your total for April is $10.00');
Pdf::assertViewHas('invoice', $invoice);
Pdf::assertSaved(storage_path('invoices/invoice.pdf'));
Pdf::assertQueued('invoice.pdf');
Pdf::assertNotQueued();
```

Detailed saved/queued assertions:

```php
Pdf::assertSaved(function (PdfBuilder $pdf, string $path) {
    return $path === storage_path('invoices/invoice.pdf')
        && $pdf->downloadName === 'invoice.pdf'
        && str_contains($pdf->html, 'Total');
});

Pdf::assertQueued(function (PdfBuilder $pdf, string $path) {
    return $path === 'invoice.pdf' && $pdf->contains('Total');
});
```

## Browsershot deployment notes

- Install Node 22+ and Puppeteer v23+.
- Ensure Chrome/Chromium is available to the runtime user.
- If binaries are not auto-detected, set `LARAVEL_PDF_NODE_BINARY`, `LARAVEL_PDF_NPM_BINARY`, `LARAVEL_PDF_INCLUDE_PATH`, `LARAVEL_PDF_CHROME_PATH`, and `LARAVEL_PDF_NODE_MODULES_PATH`.
- On Linux containers/servers, `LARAVEL_PDF_NO_SANDBOX=true` is often required, depending on host sandbox support.
- Browser-based drivers execute JavaScript in the HTML before generating the PDF; DOMPDF does not.
