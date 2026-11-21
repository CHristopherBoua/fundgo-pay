@extends('layouts.app')
@section('title', 'Paramètres - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Paramètres</h1>
<p class="muted">Clés de liaison des prestataires de paiement et routage. Une valeur saisie ici remplace celle du fichier <code>.env</code> ;
    les secrets sont chiffrés en base et ne sont jamais réaffichés (seuls les 4 derniers caractères).</p>

@foreach ($gateways as $gw => $def)
    <section class="card stack" style="margin-bottom:24px" id="gw-{{ $gw }}">
        <div class="row" style="justify-content:space-between">
            <h2 style="margin:0">{{ $def['label'] }}</h2>
            <div class="row">
                <a class="fineprint" href="{{ $def['docs'] }}" target="_blank" rel="noopener noreferrer">Documentation</a>
                @if ($def['testable'])
                    <form method="post" action="{{ route('admin.settings.test', $gw) }}">@csrf<button class="btn small secondary">Tester la connexion</button></form>
                @endif
            </div>
        </div>

        <form method="post" action="{{ route('admin.settings.gateway', $gw) }}" class="stack" autocomplete="off">
            @csrf @method('put')
            <div class="kv">
                @foreach ($def['fields'] as $f)
                    <label for="{{ $f['name'] }}" style="margin:0">
                        {{ $f['label'] }}
                        <span class="src">
                            @if ($f['source'] === 'admin') · saisi dans l'admin{{ $f['editor'] ? ' par '.$f['editor'] : '' }}
                            @elseif ($f['source'] === '.env') · depuis .env
                            @else · non défini
                            @endif
                        </span>
                    </label>
                    <div class="row" style="flex-wrap:nowrap">
                        @if ($f['type'] === 'bool')
                            <input type="hidden" name="{{ $f['name'] }}" value="0">
                            <input id="{{ $f['name'] }}" type="checkbox" name="{{ $f['name'] }}" value="1" @checked($f['value'])>
                        @elseif ($f['secret'])
                            <input id="{{ $f['name'] }}" type="password" name="{{ $f['name'] }}" value="" autocomplete="new-password" spellcheck="false"
                                   placeholder="{{ $f['value'] ? 'Défini ('.$f['value'].') - laisser vide pour conserver' : 'Non défini' }}">
                        @else
                            <input id="{{ $f['name'] }}" type="text" name="{{ $f['name'] }}" value="{{ $f['value'] }}" spellcheck="false"
                                   @if ($f['type'] === 'list') placeholder="ex. XOF,XAF,NGN" @endif>
                        @endif
                        @if ($f['source'] === 'admin')
                            <label class="fineprint" style="margin:0;white-space:nowrap;font-weight:400">
                                <input type="checkbox" name="clear[{{ $f['name'] }}]" value="1"> revenir au .env
                            </label>
                        @endif
                    </div>
                @endforeach
            </div>
            <div><button class="btn">Enregistrer {{ $def['label'] }}</button></div>
        </form>
    </section>
@endforeach

<section class="card stack" id="routage">
    <h2 style="margin:0">Routage des paiements</h2>
    <p class="fineprint" style="margin:0">Quelle passerelle traite chaque moyen de paiement, par devise. « Hériter » = règle de la colonne de gauche, puis passerelle par défaut.</p>
    <form method="post" action="{{ route('admin.settings.routing') }}" class="stack">
        @csrf @method('put')
        @php($select = function (array $cell, bool $inherit = true) use ($gatewayNames) {
            $current = (string) ($cell['value'] ?? '');
            $html = '<select name="'.e($cell['name']).'" style="width:auto">';
            if ($inherit) { $html .= '<option value="">Hériter</option>'; }
            foreach ($gatewayNames as $g) { $html .= '<option value="'.e($g).'"'.($current === $g ? ' selected' : '').'>'.e($g).'</option>'; }
            return $html.'</select>';
        })
        <div class="row"><label style="margin:0">Passerelle par défaut</label> {!! $select($defaultGateway, false) !!}</div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Moyen</th><th>Par défaut / EUR</th><th>XOF</th><th>XAF</th><th>NGN</th><th>USD</th></tr></thead>
                <tbody>
                @foreach ($routing as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td>{!! $select($row['default']) !!}</td>
                        @foreach (['XOF', 'XAF', 'NGN', 'USD'] as $cur)
                            <td>{!! $row[$cur] ? $select($row[$cur]) : '<span class="muted">-</span>' !!}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div><button class="btn">Enregistrer le routage</button></div>
    </form>
</section>
@endsection
