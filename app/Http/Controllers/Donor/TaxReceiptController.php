<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Services\TaxReceipts\IssueResult;
use App\Services\TaxReceipts\TaxReceiptRenderer;
use App\Services\TaxReceipts\TaxReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaxReceiptController extends Controller
{
    /** Demande d'un reçu pour une année close (sans attendre l'envoi annuel). */
    public function store(Request $request, TaxReceiptService $service): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year - 1)]]);
        $result = $service->issue($request->user(), (int) $data['year']);

        if ($result->issued() || $result->status === IssueResult::ALREADY_ISSUED) {
            return back()->with('status', $result->message());
        }

        return back()->withErrors(['year' => $result->message()]);
    }

    public function download(Request $request, string $receipt, TaxReceiptRenderer $renderer): Response
    {
        $receipt = $request->user()->taxReceipts()->findOrFail($receipt);

        if ($receipt->downloaded_at === null) {
            $receipt->update(['downloaded_at' => now()]);
        }

        return response($renderer->render($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt->filename().'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
