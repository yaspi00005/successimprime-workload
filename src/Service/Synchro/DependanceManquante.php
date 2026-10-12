<?php

namespace App\Service\Synchro;

/**
 * Une donnée reçue en référence une autre pas encore arrivée : l'envoi est
 * rejoué au cycle suivant (« reprise auto »), sans rien perdre.
 */
final class DependanceManquante extends \RuntimeException
{
}
