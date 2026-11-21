@extends('layouts.app')
@section('title', ($campaign->exists ? 'Modifier' : 'Nouvelle').' campagne - Administration')

@section('content')
@include('admin.partials.nav')
<h1>{{ $campaign->exists ? 'Modifier la campagne' : 'Nouvelle campagne' }}</h1>
<form method="post" action="{{ $campaign->exists ? route('admin.campaigns.update', $campaign) : route('admin.campaigns.store') }}" class="card stack" style="max-width:760px">
    @csrf @if ($campaign->exists) @method('put') @endif
    <div><label for="title">Titre</label><input id="title" type="text" name="title" value="{{ old('title', $campaign->title) }}" required></div>
    <div><label for="slug">Adresse (slug)</label><input id="slug" type="text" name="slug" value="{{ old('slug', $campaign->slug) }}" placeholder="générée depuis le titre"></div>
    <div><label for="summary">Résumé</label><input id="summary" type="text" name="summary" value="{{ old('summary', $campaign->summary) }}" maxlength="500"></div>
    <div><label for="description">Description</label><textarea id="description" name="description" rows="6">{{ old('description', $campaign->description) }}</textarea></div>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <div><label for="currency">Devise</label>
            <select id="currency" name="currency">@foreach (\App\Support\Money::supported() as $c)<option value="{{ $c }}" @selected(old('currency', $campaign->currency) === $c)>{{ $c }}</option>@endforeach</select></div>
        <div><label for="country">Pays (ISO, ex. SN)</label><input id="country" type="text" name="country" maxlength="2" value="{{ old('country', $campaign->country) }}"></div>
        <div><label for="goal">Objectif</label><input id="goal" type="text" name="goal" inputmode="decimal"
            value="{{ old('goal', $campaign->goal_cents ? \App\Support\Money::input($campaign->goal_cents, $campaign->currency) : '') }}"></div>
        <div><label for="status">Statut</label>
            <select id="status" name="status">@foreach (['draft' => 'Brouillon', 'open' => 'Ouverte', 'closed' => 'Clôturée'] as $v => $l)<option value="{{ $v }}" @selected(old('status', $campaign->status) === $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="ends_at">Fin (facultatif)</label><input id="ends_at" type="date" name="ends_at" value="{{ old('ends_at', $campaign->ends_at?->format('Y-m-d')) }}"></div>
    </div>
    <label style="font-weight:400"><input type="checkbox" name="allows_recurring" value="1" @checked(old('allows_recurring', $campaign->allows_recurring))> Accepte les dons réguliers</label>
    <label style="font-weight:400"><input type="checkbox" name="tax_deductible" value="1" @checked(old('tax_deductible', $campaign->tax_deductible))>
        Ouvre droit au reçu fiscal <span class="fineprint">(dons en euros, sans contrepartie)</span></label>
    <div class="row"><button class="btn">Enregistrer</button><a href="{{ route('admin.campaigns.index') }}">Annuler</a></div>
</form>
@endsection
