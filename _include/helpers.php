<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Fonctions utilitaires globales (échappement, sécurité)
*/

/**
 * Échappe une valeur pour affichage HTML sécurisé.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
