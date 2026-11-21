<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.campaigns.index', [
            'campaigns' => Campaign::query()
                ->withSum(['payments as raised' => fn ($q) => $q->where('status', PaymentStatus::Paid)], 'amount_cents')
                ->withCount(['payments as donations' => fn ($q) => $q->where('status', PaymentStatus::Paid)])
                ->latest()->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.campaigns.form', ['campaign' => new Campaign(['currency' => 'EUR', 'status' => 'draft', 'allows_recurring' => true, 'tax_deductible' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?: $data['title']);
        $campaign = Campaign::create($data);
        $this->audit->log('campaign.created', $campaign, after: $campaign->only(['title', 'currency', 'status', 'goal_cents']));

        return redirect()->route('admin.campaigns.index')->with('status', 'Campagne créée.');
    }

    public function edit(Campaign $campaign): View
    {
        return view('admin.campaigns.form', ['campaign' => $campaign]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $this->validated($request, $campaign);
        $before = $campaign->only(array_keys($data));

        // La devise ne change plus une fois des paiements reçus (montants stockés en unités mineures).
        if ($data['currency'] !== $campaign->currency && $campaign->payments()->exists()) {
            return back()->withInput()->withErrors(['currency' => 'Impossible de changer la devise : la campagne a déjà des paiements.']);
        }

        $data['slug'] = $data['slug'] && $data['slug'] !== $campaign->slug ? $this->uniqueSlug($data['slug']) : $campaign->slug;
        $campaign->update($data);
        $this->audit->log('campaign.updated', $campaign, before: $before, after: $campaign->only(array_keys($data)));

        return redirect()->route('admin.campaigns.index')->with('status', 'Campagne mise à jour.');
    }

    private function validated(Request $request, ?Campaign $campaign = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'currency' => ['required', Rule::in(Money::supported())],
            'country' => ['nullable', 'string', 'size:2'],
            'goal' => ['nullable', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
            'allows_recurring' => ['nullable', 'boolean'],
            'tax_deductible' => ['nullable', 'boolean'],
            'ends_at' => ['nullable', 'date'],
        ]);

        return [
            'title' => $data['title'],
            'slug' => $data['slug'] ?? null,
            'summary' => $data['summary'] ?? null,
            'description' => $data['description'] ?? null,
            'currency' => $data['currency'],
            'country' => isset($data['country']) ? strtoupper($data['country']) : null,
            'goal_cents' => filled($data['goal'] ?? null) ? Money::toMinor($data['goal'], $data['currency']) : null,
            'status' => $data['status'],
            'allows_recurring' => $request->boolean('allows_recurring'),
            'tax_deductible' => $request->boolean('tax_deductible') && $data['currency'] === 'EUR',
            'ends_at' => $data['ends_at'] ?? null,
        ];
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'campagne';
        $slug = $base;
        $i = 2;
        while (Campaign::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
