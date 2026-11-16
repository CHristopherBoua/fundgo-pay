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
| `tax-receipts:issue [année] [--dry-run]` | Reçus fiscaux de l'année écoulée (planifié le 15 janvier). Idempotente |

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

La suite compte 48 tests : parcours complets (SEPA ponctuel et mensuel, carte 3DS puis MIT, relances, révocation de mandat, rejet, idempotence des webhooks, gestion depuis l'espace donateur, IDOR), intégrité du grand livre, reçus fiscaux (éligibilité, numérotation, rectificatif, PDF, accès), et drivers Mollie et Stripe testés contre des réponses HTTP enregistrées (aucun appel réseau).

## Reste à faire

- Réconciliation des relevés bancaires CAMT.053 avec les versements du PSP et le grand livre ;
- Back-office administrateur (campagnes, remboursements, export comptable) ;
- Réinitialisation du mot de passe et vérification de l'e-mail ;
- Tests contre les sandbox Mollie et Stripe réelles (clés de test).
