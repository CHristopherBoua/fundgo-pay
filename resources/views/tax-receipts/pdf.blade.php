@php
    $fmt = fn (int $c) => number_format($c / 100, 2, ',', ' ').' €';
    $box = fn (bool $on) => $on ? '&#9746;' : '&#9744;';
    $article = (string) ($org['cgi_article'] ?? '200');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Reçu fiscal {{ $r->number }}</title>
<style>
    @page { margin: 12mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; line-height: 1.3; }
    p { margin: 1.5mm 0; }
    h1 { font-size: 12pt; text-align: center; margin: 0 0 1mm; }
    .sub { text-align: center; font-size: 8pt; margin-bottom: 3mm; }
    .meta { width: 100%; margin-bottom: 3mm; font-size: 9pt; }
    .meta td { padding: 0; }
    .section { border: 1px solid #111; margin-bottom: 3mm; }
    .section h2 { margin: 0; padding: 1mm 3mm; font-size: 9.5pt; background: #e6e6e6; border-bottom: 1px solid #111; }
    .section .body { padding: 2mm 3mm; }
    .label { color: #444; font-size: 8pt; }
    .amount { font-size: 13pt; font-weight: bold; }
    .grid { width: 100%; border-collapse: collapse; }
    .grid td { vertical-align: top; padding: 0.5mm 0; }
    .footer { page-break-inside: avoid; }
    .lines { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
    .lines th, .lines td { border-bottom: 0.5px solid #999; padding: 1mm 1.5mm; text-align: left; }
    .lines td.r, .lines th.r { text-align: right; }
    .cancelled { position: fixed; top: 95mm; left: 10mm; right: 10mm; text-align: center; font-size: 60pt; color: rgba(200, 0, 0, 0.18); transform: rotate(-25deg); font-weight: bold; }
    .note { font-size: 7.5pt; color: #444; }
    .sign img { max-height: 22mm; }
    .page-break { page-break-before: always; }
</style>
</head>
<body>
@unless ($r->isIssued())
    <div class="cancelled">ANNULÉ</div>
@endunless

<h1>Reçu au titre des dons à certains organismes d'intérêt général</h1>
<div class="sub">Articles 200, 238 bis et 978 du code général des impôts (CGI) - modèle cerfa n° 11580*05</div>

<table class="meta"><tr>
    <td><strong>Numéro d'ordre du reçu : {{ $r->number }}</strong></td>
    <td style="text-align:right">Reçu récapitulatif annuel {{ $r->fiscal_year }}</td>
</tr></table>

@if ($replaces)
    <p><strong>Reçu rectificatif</strong> : annule et remplace le reçu n° {{ $replaces->number }} du {{ $replaces->issued_at->format('d/m/Y') }}.</p>
@endif

<div class="section">
    <h2>Bénéficiaire des versements</h2>
    <div class="body">
        <table class="grid">
            <tr><td style="width:35%" class="label">Nom ou dénomination</td><td><strong>{{ $org['name'] }}</strong></td></tr>
            <tr><td class="label">Adresse</td><td>{{ $org['address_line1'] }}, {{ $org['postal_code'] }} {{ $org['city'] }}{{ ! empty($org['country']) ? ', '.$org['country'] : '' }}</td></tr>
            <tr><td class="label">Identifiant</td><td>{{ $org['identifier'] }}</td></tr>
            <tr><td class="label">Objet</td><td>{{ $org['purpose'] }}</td></tr>
            <tr><td class="label">Qualité de l'organisme</td><td>{!! $box(true) !!} {{ $org['quality'] }}</td></tr>
        </table>
    </div>
</div>

<div class="section">
    <h2>Donateur</h2>
    <div class="body">
        <table class="grid">
            <tr><td style="width:35%" class="label">Nom, prénoms</td><td><strong>{{ $donor['name'] }}</strong></td></tr>
            <tr><td class="label">Adresse</td><td>
                {{ $donor['address_line1'] }}@if (! empty($donor['address_line2'])), {{ $donor['address_line2'] }}@endif<br>
                {{ $donor['postal_code'] }} {{ $donor['city'] }} @if (! empty($donor['country']) && $donor['country'] !== 'FR')({{ $donor['country'] }})@endif
            </td></tr>
        </table>
    </div>
</div>

<div class="section">
    <h2>Versements</h2>
    <div class="body">
        <p style="margin-top:0">Le bénéficiaire reconnaît avoir reçu au titre des dons et versements ouvrant droit à réduction d'impôt, la somme de :</p>
        <p class="amount">*** {{ $fmt($r->amount_cents) }} ***</p>
        <p><span class="label">Somme en toutes lettres :</span> {{ $amountInWords }}</p>
        <p><span class="label">Période des versements :</span> du 1er janvier au 31 décembre {{ $r->fiscal_year }} ({{ count($r->payments) }} versement(s), détail en annexe)</p>

        <p>Le bénéficiaire certifie sur l'honneur que les dons et versements qu'il reçoit ouvrent droit à la réduction d'impôt prévue à l'article :<br>
            {!! $box($article === '200') !!} 200 du CGI &nbsp;&nbsp; {!! $box($article === '238 bis') !!} 238 bis du CGI &nbsp;&nbsp; {!! $box($article === '978') !!} 978 du CGI</p>

        <table class="grid">
            <tr>
                <td style="width:33%"><span class="label">Forme du don</span><br>{!! $box(true) !!} {{ $org['form'] }}</td>
                <td style="width:33%"><span class="label">Nature du don</span><br>{!! $box(true) !!} {{ $org['nature'] }}</td>
                <td><span class="label">Mode de versement</span><br>{!! $box(true) !!} {{ $org['payment_mode'] }}</td>
            </tr>
        </table>
    </div>
</div>

<table class="grid footer">
    <tr>
        <td style="width:55%">
            <span class="label">Date d'émission</span><br>{{ $r->issued_at->format('d/m/Y') }}
            <p class="note">Réduction d'impôt indicative : {{ $fmt($r->estimatedReductionCents()) }}
                ({{ $org['reduction_rate_percent'] }} % des versements, dans la limite de 20 % du revenu imposable ; l'excédent est reportable sur 5 ans).</p>
        </td>
        <td class="sign">
            <span class="label">Signature</span><br>
            @if ($signature)<img src="{{ $signature }}" alt="Signature"><br>@endif
            {{ $org['signatory_name'] }}, {{ $org['signatory_role'] }}
        </td>
    </tr>
</table>

<p class="note">Empreinte d'intégrité : {{ $r->content_sha256 }}</p>

<div class="page-break"></div>
<h1>Annexe - détail des versements {{ $r->fiscal_year }}</h1>
<div class="sub">Reçu n° {{ $r->number }} - {{ $donor['name'] }}</div>
<table class="lines">
    <thead><tr><th>Date d'encaissement</th><th>Affectation</th><th>Type</th><th class="r">Montant</th></tr></thead>
    <tbody>
    @foreach ($r->payments as $line)
        <tr>
            <td>{{ \Carbon\CarbonImmutable::parse($line['date'])->format('d/m/Y') }}</td>
            <td>{{ $line['campaign'] ?? 'Fonds général' }}</td>
            <td>Don {{ $line['kind'] }}</td>
            <td class="r">{{ $fmt($line['amount_cents']) }}</td>
        </tr>
    @endforeach
    <tr><td colspan="3"><strong>Total</strong></td><td class="r"><strong>{{ $fmt($r->amount_cents) }}</strong></td></tr>
    </tbody>
</table>
</body>
</html>
