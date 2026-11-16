<?php

namespace App\Services\TaxReceipts;

use App\Models\TaxReceipt;
use App\Support\AmountInWords;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Rend le PDF d'un reçu à partir de son instantané figé : le même reçu produit toujours
 * le même contenu, même si le profil du donateur ou la configuration ont changé depuis.
 */
class TaxReceiptRenderer
{
    public function render(TaxReceipt $receipt): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);     // aucune ressource distante (SSRF)
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');  // accents et symbole €
        $options->setTempDir(sys_get_temp_dir());
        $fontCache = (string) config('tax_receipts.font_cache');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }
        $options->setFontCache($fontCache);
        $options->setChroot([base_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->html($receipt), 'UTF-8');
        $dompdf->addInfo('Title', 'Reçu fiscal '.$receipt->number);
        $dompdf->addInfo('Author', (string) ($receipt->organization['name'] ?? ''));
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function html(TaxReceipt $receipt): string
    {
        return view('tax-receipts.pdf', [
            'r' => $receipt,
            'org' => $receipt->organization,
            'donor' => $receipt->donor,
            'amountInWords' => AmountInWords::euros($receipt->amount_cents),
            'signature' => $this->signatureDataUri($receipt->organization['signature_image'] ?? null),
            'replaces' => $receipt->replaces,
        ])->render();
    }

    private function signatureDataUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $full = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : base_path($path);
        $real = realpath($full);
        if (! $real || ! str_starts_with($real, realpath(base_path())) || ! is_file($real)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($real));
    }
}
