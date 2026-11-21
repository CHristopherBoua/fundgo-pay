# FundGo Pay

Plateforme **privée** de dons. Laravel 12 + [Omnipay](https://omnipay.thephpleague.com/).

Un donateur peut :

- faire un **don ponctuel** (prélèvement SEPA immédiat, ou carte via la page sécurisée du PSP) ;
- mettre en place un **don régulier**, mensuel, trimestriel ou annuel, prélevé automatiquement (mandat SEPA, ou carte enregistrée par 3DS puis débitée hors session) ;
- se connecter à son **espace donateur** pour voir son historique et ses totaux, et pour **modifier le montant, suspendre, reprendre, changer de moyen de paiement ou arrêter** son don sans contacter personne ;
- recevoir chaque année son **reçu fiscal** (cerfa 11580*05) en PDF, par e-mail et dans son espace.

## Démarrage

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Ouvrir http://localhost:8000. Le compte de démo est `demo@fundgo.test` ; son mot de passe se trouve dans `database/seeders/DatabaseSeeder.php`.
Par défaut, la passerelle **`fake`** simule un PSP sans réseau :

- un titulaire contenant `FAIL` provoque un rejet AM04 (provision insuffisante), ce qui déclenche les relances ;
- un titulaire contenant `REVOKED` provoque un rejet MD01 (mandat révoqué) ;
- la page de carte simulée propose « Payer », « Refuser » ou « Annuler ».

IBAN de test valide : `FR76 3000 6000 0112 3456 7890 189`.

Tâches planifiées (cron : `* * * * * php artisan schedule:run`) :

| Commande | Rôle |
|---|---|
| `donations:prenotify` | Pré-notification SEPA à J-14 |
| `donations:charge-due` | Encaisse les échéances dues. Idempotente, on peut la relancer sans risque |
| `donations:retry-failed` | Relances (dunning) à J+3 puis J+7 |
| `payments:replay-events --failed` | Rejoue les webhooks en erreur |
| `ledger:verify [--balance]` | Vérifie la chaîne de hash du grand livre |
| `donations:remind-installments` | Relances et expiration des échéances « à régler par lien » |
| `tax-receipts:issue [année] [--dry-run]` | Reçus fiscaux de l'année écoulée (planifié le 15 janvier). Idempotente |

## Administration

**Créer le super-administrateur** (le mot de passe est demandé dans le terminal, il n'est stocké nulle part ailleurs) :

```bash
php artisan admin:create vous@exemple.org --name="Votre nom"
```

Connectez-vous ensuite normalement, puis ouvrez **`/admin`** (lien « Administration » dans l'en-tête). À la première visite, la **double authentification** se configure : vous scannez ou saisissez la clé dans une application d'authentification (Google Authenticator, Microsoft Authenticator, 1Password…). Le code est ensuite redemandé à chaque nouvelle session, et au plus tard toutes les 12 h (`ADMIN_2FA_TTL`). En cas de téléphone perdu : `php artisan admin:reset-2fa vous@exemple.org`.

| Rôle | Accès |
|---|---|
| `admin` | Tableau de bord, campagnes, paiements et remboursements via API, donateurs, reçus fiscaux, webhooks, journal |
| `superadmin` | Tout, plus : **Paramètres** (clés de liaison et routage), gestion des administrateurs, remboursements enregistrés manuellement, annulation de reçus |

Pour ajouter un administrateur, la personne s'inscrit d'abord avec son propre mot de passe, puis un super-admin la promeut dans **Administrateurs**. Pour un visiteur non administrateur, l'espace `/admin` répond 404 : son existence n'est pas révélée.

### Paramètres : clés de liaison des passerelles

Dans **Administration > ⚙ Paramètres**, le super-admin saisit les clés de chaque passerelle :
- **UBA** : clé publique, clé privée, pays par devise, tokenisation ;
- **Ecobank** : URL, subscription key, client ID, secret key, affiliate et source codes, IP du serveur… ;
- **Mollie** : clé API ;
- **Stripe** : clé secrète et secret de signature des webhooks.

Il y règle aussi le **routage** : quelle passerelle traite quel moyen de paiement, pour quelle devise.

- Une valeur saisie dans l'admin **remplace** celle du `.env`. La case « revenir au .env » l'efface.
- Les valeurs sont **chiffrées en base** avec `APP_KEY`. Les secrets ne sont jamais réaffichés (seulement `••••` et les 4 derniers caractères). Un champ secret laissé vide conserve la valeur existante. Le cache ne contient que des valeurs chiffrées.
- Le bouton **Tester la connexion** (UBA, Ecobank) demande un vrai jeton au prestataire avec les clés saisies.
- Chaque modification est tracée dans le journal d'audit : qui, quand et quelles clés, **jamais les valeurs**.

> ⚠️ Changer `APP_KEY` rend illisibles les clés enregistrées dans l'admin : il faut alors les ressaisir.

## Banques africaines : UBA et Ecobank

Devises prises en charge : **EUR, USD, XOF, XAF, NGN**. Les montants sont stockés en unités mineures : centimes, kobo, et francs pour XOF/XAF qui n'ont pas de subdivision. La devise d'un don est celle de sa campagne ; les moyens proposés dépendent de la devise (`config/payments.php` > `currencies`). Le routage se fait par devise et par moyen de paiement (`PAYMENTS_XOF_CARD_GATEWAY=uba`, `PAYMENTS_XOF_ECOBANK_GATEWAY=ecobank`, etc.).

| | UBA (API SeerBit) | Ecobank Direct Debit |
|---|---|---|
| Passerelle Omnipay | `SeerbitGateway` (écrite pour le projet) | `EcobankGateway` (écrite pour le projet) |
| Don ponctuel | Page de paiement hébergée UBA (Standard Checkout) | Débit du compte, validé par OTP Ecobank Authenticator |
| Don régulier | Prélèvement automatique si `UBA_HOSTED_TOKENIZATION=true`, sinon lien de paiement à chaque échéance | Lien de paiement à chaque échéance (OTP obligatoire à chaque débit) |
| Webhooks | Non signés : chaque événement est vérifié en relisant le statut via l'API | Aucun webhook documenté : la validation OTP fait foi |
| Remboursement | Depuis le tableau de bord marchand UBA | Non disponible par API |

**Lien de paiement à chaque échéance** : à chaque date due, le donateur reçoit un e-mail « Régler mon don ». Il est relancé à J+3 et J+7, et le lien expire à J+14. Après 2 échéances manquées d'affilée, le don passe en « à régulariser ». La première échéance est payée immédiatement à l'inscription (`donations:remind-installments`, planifiée chaque jour).

**Ecobank** : à son premier paiement, le donateur lie son compte une seule fois (code reçu par e-mail/SMS, création du profil, code de l'application Ecobank Authenticator). Ensuite, chaque paiement se valide avec un code Authenticator.

> ⚠️ **À confirmer avant mise en production**
> - **UBA** : la tokenisation via la page hébergée n'est pas documentée. L'API d'abonnement documentée (`/recurrent/plan/create`) exige d'envoyer le numéro de carte depuis notre serveur, ce qui fait entrer la plateforme dans le périmètre PCI DSS ; elle n'est **volontairement pas utilisée**. Demander à SeerBit si `tokenize` est accepté sur `/api/v2/payments` et où se trouve l'`authorizationCode` dans la réponse de statut. Le format des montants XOF (`"5000"`) est également à valider en sandbox.
> - **Ecobank** : la documentation publique fournit les chemins et les formules de hash SHA-512, que nous avons implémentés. En revanche, le payload exact, les `requestType`, le format de réponse et l'endpoint de jeton ne sont visibles que dans la sandbox (connexion requise). Ils sont configurables (`ECOBANK_*`) et marqués « sandbox » dans `config/payments.php`.
> - Les **reçus fiscaux** ne concernent que les dons en euros (cerfa français).

## Reçus fiscaux

Un **reçu récapitulatif annuel** par donateur, selon le modèle cerfa 11580*05, avec une annexe qui détaille chaque versement.

- **Ce qui est couvert** : les paiements encaissés dans l'année civile, sur les campagnes marquées `tax_deductible`. Un don avec contrepartie, comme un billet de gala, en est exclu.
- **Numérotation** : `AAAA-NNNNNN`, continue par année, attribuée sous verrou. Il ne peut y avoir ni trou ni doublon.
- **Immuable** : à l'émission, on fige l'identité du donateur, celle de l'organisme et la liste des paiements, avec une empreinte SHA-256. Le PDF est regénéré à l'identique à chaque téléchargement. Le modèle refuse toute modification ou suppression.
- **Remboursement ou rejet** d'un don déjà reçu : le reçu est **annulé** et un **reçu rectificatif** est émis automatiquement, avec un nouveau numéro et la mention « annule et remplace ». Si plus rien n'est éligible, le reçu est seulement annulé et le donateur prévenu.
- **Adresse obligatoire** : sans adresse complète, aucun reçu n'est émis. L'espace donateur le signale, et le donateur peut obtenir son reçu lui-même une fois l'adresse complétée (bouton « Obtenir mon reçu »).
- **Configuration** : les informations de l'organisme se règlent dans `.env` ou `config/tax_receipts.php` (dénomination, RNA/SIREN, objet, qualité, signataire, signature scannée, article du CGI).

> ⚠️ Délivrer des reçus sans y être éligible expose l'organisme à une amende de 20 % des sommes mentionnées (art. 1740 A du CGI). Faites valider l'éligibilité et les mentions par votre expert-comptable avant la mise en production.

## Architecture

```
app/Payments/                     ← couche PSP-agnostique
  Contracts/PaymentDriver.php     contrat unique vu par le métier
  GatewayManager.php              résout le driver (config/payments.php), routage par moyen de paiement
  Drivers/AbstractOmnipayDriver   socle Omnipay (création de la passerelle, erreurs, montants)
  Drivers/MollieDriver            omnipay/mollie : mandats directdebit, first/recurring, webhook relu par API
  Drivers/StripeDriver            omnipay/stripe PaymentIntents + passerelle étendue
  Drivers/FakeDriver              PSP simulé (dev, démo, tests)
  Omnipay/Stripe/                 FundgoStripeGateway : SEPA SetupIntent, Checkout, MIT, refund
app/Services/
  Payments/PaymentProcessor       machine à états des paiements, SEUL point d'application d'un statut
  Payments/ChargeService          échéances, relances, anti-double-débit
  Payments/MandateService         signature électronique du mandat SEPA (RUM, ICS, preuve)
  Payments/GatewayEventIngestor   webhooks idempotents + rejeu
  Donations/DonationService       orchestration don ponctuel / régulier
  Donations/RecurringDonationService  cycle de vie du don régulier, pré-notifications
  Ledger/LedgerService            grand livre en partie double, append-only, chaîné SHA-256
```

### Changer de PSP

Le cœur ne connaît que `PaymentDriver`. Changer de PSP revient à changer une variable d'environnement, et on peut répartir par moyen de paiement :

```dotenv
PAYMENTS_SEPA_GATEWAY=mollie   # prélèvements SEPA via Mollie
PAYMENTS_CARD_GATEWAY=stripe   # cartes via Stripe
```

Pour ajouter un PSP compatible Omnipay (GoCardless, Adyen, PayPlug…) : installer son driver Omnipay, écrire une classe `XxxDriver extends AbstractOmnipayDriver`, puis la déclarer dans `config/payments.php`. Si le driver Omnipay ne couvre pas une opération (cas de Stripe pour le SEPA), on **étend la passerelle Omnipay** avec de nouvelles requêtes, comme `FundgoStripeGateway`.

> Omnipay est conçu pour le paiement ponctuel. Les mandats et le récurrent dépendent de chaque driver ; c'est pourquoi la logique d'échéancier, de relances et de pré-notification vit **chez nous** et non chez le PSP.

### Garanties

- **Pas de double débit** : la ligne `payments` (unique par don, échéance et tentative) est créée et l'échéancier avancé dans la même transaction. L'appel au PSP se fait après le commit, avec une clé d'idempotence (Stripe).
- **Webhooks** : signature vérifiée (Stripe), ou transaction relue par API (Mollie). Stockage brut, unicité `(gateway, event_id)`, transitions d'état monotones (un webhook arrivé en retard est ignoré), rejeu possible.
- **Grand livre** : chaque encaissement donne débit `psp:{gw}:clearing` / crédit `fund:campaign:{id}`. Les remboursements et rejets sont contre-passés. Des triggers SQL interdisent UPDATE et DELETE, et `ledger:verify` détecte toute altération, même si les triggers ont été supprimés.
- **Données sensibles** : l'IBAN complet n'est jamais stocké (on garde les 4 derniers caractères et une empreinte HMAC) ; aucun PAN.
- **Audit** : `audit_logs` enregistre qui, quoi, avant/après, IP et user-agent.
- **SEPA** : RUM unique, ICS, texte du mandat versionné, preuve d'acceptation (IP, UA, horodatage), première échéance à J+14 au plus tôt, pré-notification de chaque échéance.

## Tests

```bash
php artisan test
```

La suite compte 79 tests : back-office (rôles, double authentification TOTP, paramètres chiffrés, remboursements), parcours complets (SEPA ponctuel et mensuel, carte 3DS puis MIT, relances, révocation de mandat, rejet, idempotence des webhooks, gestion depuis l'espace donateur, IDOR), échéances par lien de paiement (relances, expiration, nouvelle tentative), multi-devises, intégrité du grand livre, reçus fiscaux (éligibilité, numérotation, rectificatif, PDF, accès), et drivers Mollie, Stripe, UBA et Ecobank testés contre des réponses HTTP enregistrées (aucun appel réseau).

- Banque « BSCA » : à ajouter dès que son nom complet et sa documentation d'API sont connus ;

## Reste à faire

- Réconciliation des relevés bancaires CAMT.053 avec les versements du PSP et le grand livre ;
- Export comptable (FEC / CSV) depuis le back-office ;
- Réinitialisation du mot de passe et vérification de l'e-mail ;
- Tests contre les sandbox Mollie et Stripe réelles (clés de test).
