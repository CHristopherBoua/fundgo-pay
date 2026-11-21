@extends('layouts.app')
@section('title', 'Journal - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Journal d'audit</h1>
<form method="get" class="row" style="margin:12px 0 16px">
    <input type="text" name="action" value="{{ $filters['action'] ?? '' }}" placeholder="Action (ex. payment., settings.)" style="max-width:260px">
    <input type="text" name="entity" value="{{ $filters['entity'] ?? '' }}" placeholder="Id de l'entité" style="max-width:260px">
    <button class="btn small">Filtrer</button>
</form>
<div class="card table-scroll"><table>
    <thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Entité</th><th>Détail</th><th>IP</th></tr></thead>
    <tbody>
    @foreach ($logs as $log)
        <tr>
            <td>{{ $log->occurred_at->format('d/m/Y H:i:s') }}</td>
            <td>{{ $log->actor }}</td>
            <td><code>{{ $log->action }}</code></td>
            <td>{{ $log->entity_type }}<div class="fineprint" style="word-break:break-all">{{ $log->entity_id }}</div></td>
            <td class="fineprint" style="max-width:360px;word-break:break-word">
                @if ($log->before)avant : {{ json_encode($log->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}<br>@endif
                @if ($log->after)après : {{ json_encode($log->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}@endif
            </td>
            <td class="fineprint">{{ $log->ip }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div style="margin-top:12px">{{ $logs->links() }}</div></div>
@endsection
