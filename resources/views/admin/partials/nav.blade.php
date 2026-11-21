@php($is = fn (string $pattern) => request()->routeIs($pattern) ? 'on' : '')
<nav class="admin-nav" aria-label="Administration">
    <a class="{{ $is('admin.dashboard') }}" href="{{ route('admin.dashboard') }}">Tableau de bord</a>
    <a class="{{ $is('admin.campaigns.*') }}" href="{{ route('admin.campaigns.index') }}">Campagnes</a>
    <a class="{{ $is('admin.payments.*') }}" href="{{ route('admin.payments.index') }}">Paiements</a>
    <a class="{{ $is('admin.donors') }}" href="{{ route('admin.donors') }}">Donateurs</a>
    <a class="{{ $is('admin.receipts*') }}" href="{{ route('admin.receipts') }}">Reçus fiscaux</a>
    <a class="{{ $is('admin.webhooks*') }}" href="{{ route('admin.webhooks') }}">Webhooks</a>
    <a class="{{ $is('admin.audit') }}" href="{{ route('admin.audit') }}">Journal</a>
    @if (auth()->user()->isSuperAdmin())
        <span class="sep"></span>
        <a class="{{ $is('admin.settings*') }}" href="{{ route('admin.settings') }}">⚙ Paramètres</a>
        <a class="{{ $is('admin.admins*') }}" href="{{ route('admin.admins') }}">Administrateurs</a>
    @endif
</nav>
