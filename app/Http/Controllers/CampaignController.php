<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethodType;
use App\Models\Campaign;
use App\Payments\GatewayManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function index(): View
    {
        return view('campaigns.index', [
            'campaigns' => Campaign::query()->where('status', 'open')->latest()->get(),
        ]);
    }

    public function show(Request $request, Campaign $campaign, GatewayManager $gateways): View
    {
        abort_if($campaign->status === 'draft', 404);

        // Seuls les moyens enregistrés chez un PSP qui accepte la devise de la campagne.
        $saved = ($request->user()?->paymentMethods()->active()->latest()->get() ?? collect())
            ->filter(function ($m) use ($gateways, $campaign) {
                try {
                    return $gateways->driver($m->gateway)->supportsCurrency($campaign->currency);
                } catch (\InvalidArgumentException) {
                    return false;
                }
            })->values();

        return view('campaigns.show', [
            'campaign' => $campaign,
            'savedMethods' => $saved,
            'methods' => PaymentMethodType::forCurrency($campaign->currency),
        ]);
    }
}
