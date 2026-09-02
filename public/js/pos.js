document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        const input = document.querySelector('input[name="Search"]');
        if (input) {
            input.focus();
        }
    }, 200);
});

document.addEventListener('livewire:navigated', function() {
    setTimeout(() => {
        const input = document.querySelector('input[name="Search"]');
        if (input) {
            input.focus();
        }
    }, 100);
});

let lastReceiptPrintAt = 0;

function receiptDocument(html) {
    return `
        <html>
        <head>
            <title>Chek</title>
            <style>
                @page { size: 80mm auto; margin: 0 }
                body {
                    font-family: 'Courier New', monospace;
                    font-size: 12px;
                    margin: 0;
                    padding: 0;
                    background: #fff;
                }
                .receipt {
                    width: 76mm;
                    margin: 0 auto;
                    padding-left: 2mm;
                    padding-right: 2mm;
                    page-break-inside: avoid;
                }
                .center { text-align: center }
                .right { text-align: right }
                .bold { font-weight: 700 }
                .item-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    margin: 1mm 0;
                    page-break-inside: avoid;
                }
                .item-name { flex: 1 }
                .item-total { text-align: right; min-width: 24mm }
                .line { border-bottom: 1px dashed #000; margin: 2mm 0 }
                .receipt img {
                    display: block;
                    margin: 0 auto 2mm auto;
                    max-width: 40mm;
                    max-height: 40mm;
                }
            </style>
        </head>
        <body>
            <div class="receipt">
                ${html}
            </div>
        </body>
        </html>
    `;
}

function receiptHtmlFromEvent(event) {
    if (event?.detail?.html) {
        return event.detail.html;
    }

    if (event?.html) {
        return event.html;
    }

    const src = document.getElementById('receipt-content');

    return src ? src.innerHTML : null;
}

function printReceipt(html) {
    if (!html) {
        return false;
    }

    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.left = '-10000px';
    iframe.style.top = '0';
    iframe.style.width = '80mm';
    iframe.style.height = '100vh';
    iframe.style.border = '0';
    iframe.style.opacity = '0';

    document.body.appendChild(iframe);

    const printWindow = iframe.contentWindow;
    const printDocument = printWindow?.document;

    if (!printWindow || !printDocument) {
        iframe.remove();

        return false;
    }

    iframe.addEventListener('load', async () => {
        await waitForReceiptResources(printDocument);

        printWindow.focus();
        printWindow.onafterprint = () => iframe.remove();
        printWindow.print();
        setTimeout(() => iframe.remove(), 10000);
    }, { once: true });

    printDocument.open();
    printDocument.write(receiptDocument(html));
    printDocument.close();

    return true;
}

function waitForReceiptResources(printDocument) {
    const imagePromises = Array.from(printDocument.images).map((image) => {
        if (image.complete) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
        });
    });

    const fontsReady = printDocument.fonts?.ready ?? Promise.resolve();

    return Promise.race([
        Promise.all([fontsReady, ...imagePromises]),
        new Promise((resolve) => setTimeout(resolve, 3000)),
    ]);
}

function scheduleReceiptPrint(event, attempt = 1) {
    setTimeout(() => {
        const html = receiptHtmlFromEvent(event);

        if (!printReceipt(html) && attempt < 5) {
            scheduleReceiptPrint(event, attempt + 1);
        }
    }, 50);
}

function handleReceiptPrint(event) {
    const now = Date.now();

    if (now - lastReceiptPrintAt < 500) {
        return;
    }

    lastReceiptPrintAt = now;
    scheduleReceiptPrint(event);
}

document.addEventListener('print-receipt', handleReceiptPrint);
window.addEventListener('print-receipt', handleReceiptPrint);

function registerLivewireReceiptPrintListener() {
    if (!window.Livewire) {
        return;
    }

    window.Livewire.on('print-receipt', handleReceiptPrint);
}

if (window.Livewire) {
    registerLivewireReceiptPrintListener();
} else {
    document.addEventListener('livewire:init', registerLivewireReceiptPrintListener);
}
