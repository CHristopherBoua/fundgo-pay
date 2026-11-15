<?php

namespace App\Enums;

/**
 * Séquence de paiement au sens PSP / SEPA :
 * - oneoff    : don ponctuel ;
 * - first     : premier paiement initié par le donateur (CIT, 3DS) qui enrôle le moyen de paiement ;
 * - recurring : paiement initié par la plateforme (MIT / prélèvement RCUR) sur un mandat existant.
 */
enum SequenceType: string
{
    case OneOff = 'oneoff';
    case First = 'first';
    case Recurring = 'recurring';
}
