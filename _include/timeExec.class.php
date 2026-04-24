<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class timeExec
{
    private float $timestart;

    public function __construct()
    {
        $this->timestart = microtime(true);
    }

    public function getTime(): string
    {
        return number_format(microtime(true) - $this->timestart, 3);
    }
}
