@extends('layouts.app')
@section('title', 'Webhooks - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Webhooks</h1>
<p class="muted">Notifications reçues des prestataires. Les événements en erreur sont rejoués automatiquement chaque heure ; vous pouvez aussi les rejouer ici.</p>
<div class="card table-scroll"><table>
    <thead><tr><th>Reçu le</th><th>Passerelle</th><th>Événement</th><th>Référence</th><th>État</th><th></th></tr></thead>
    <tbody>
    @forelse ($events as $e)
        <tr>
            <td>{{ $e->created_at->format('d/m/Y H:i:s') }}</td>
            <td>{{ $e->gateway }}</td>
            <td>{{ $e->type }}<div class="fineprint" style="word-break:break-all">{{ $e->event_id }}</div></td>
            <td class="fineprint" style="word-break:break-all">{{ $e->gateway_reference }}</td>
            <td>
                @if ($e->processed_at)<span class="badge paid">Traité</span>
                @elseif ($e->error)<span class="badge failed">Erreur</span><div class="fineprint">{{ \Illuminate\Support\Str::limit($e->error, 120) }}</div>
                @else<span class="badge pending">En attente</span>@endif
                <div class="fineprint">{{ $e->attempts }} tentative(s)</div>
            </td>
            <td>@unless ($e->processed_at)
                <form method="post" action="{{ route('admin.webhooks.replay', $e->id) }}">@csrf<button class="btn small secondary">Rejouer</button></form>
            @endunless</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Aucun webhook reçu.</td></tr>
    @endforelse
    </tbody>
</table>
<div style="margin-top:12px">{{ $events->links() }}</div></div>
@endsection
