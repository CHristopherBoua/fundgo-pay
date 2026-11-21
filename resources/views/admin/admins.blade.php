@extends('layouts.app')
@section('title', 'Administrateurs - Administration')

@section('content')
@include('admin.partials.nav')
<h1>Administrateurs</h1>
<div class="card table-scroll" style="margin-bottom:24px"><table>
    <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Double authentification</th><th></th></tr></thead>
    <tbody>
    @foreach ($admins as $a)
        <tr>
            <td>{{ $a->name }}</td>
            <td>{{ $a->email }}</td>
            <td><span class="badge {{ $a->isSuperAdmin() ? 'active' : '' }}">{{ $a->role }}</span></td>
            <td>{!! $a->hasTwoFactor() ? '<span class="badge paid">Activée</span>' : '<span class="badge pending">À configurer</span>' !!}</td>
            <td>@unless ($a->is(auth()->user()))
                <form method="post" action="{{ route('admin.admins.revoke', $a->id) }}" onsubmit="return confirm('Retirer les droits de {{ $a->email }} ?')">
                    @csrf @method('delete')<button class="btn small danger">Retirer</button>
                </form>
            @endunless</td>
        </tr>
    @endforeach
    </tbody>
</table></div>

<section class="card stack" style="max-width:560px">
    <h2 style="margin:0">Ajouter un administrateur</h2>
    <p class="fineprint" style="margin:0">La personne crée d'abord son compte (inscription) avec son propre mot de passe, puis vous la promouvez ici. Elle configurera sa double authentification à sa première visite.</p>
    <form method="post" action="{{ route('admin.admins.grant') }}" class="row">
        @csrf
        <input type="email" name="email" required placeholder="email@exemple.org" style="max-width:260px" aria-label="E-mail">
        <select name="role" style="width:auto" aria-label="Rôle"><option value="admin">admin</option><option value="superadmin">superadmin</option></select>
        <button class="btn small">Promouvoir</button>
    </form>
</section>
@endsection
