<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
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

    public function show(Request $request, Campaign $campaign): View
    {
        abort_if($campaign->status === 'draft', 404);

        return view('campaigns.show', [
            'campaign' => $campaign,
            'savedMethods' => $request->user()?->paymentMethods()->active()->latest()->get() ?? collect(),
        ]);
    }
}
