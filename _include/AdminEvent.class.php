<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Gestion des événements silo (CRUD).
* Extrait de administration.class.php — Phase 5 sous-commit 5.3.
*/
class AdminEvent extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /**
     * Return all silo events ordered by date descending.
     */
    public function getEvents(): void
    {
        $r = [];

        $q = 'SELECT id, '
           . "DATE_FORMAT(event_date,'%d/%m/%Y') AS event_date, "
           . 'quantity, '
           . 'remaining, '
           . 'price, '
           . 'event_type '
           . 'FROM oko_silo_events '
           . 'ORDER BY oko_silo_events.event_date DESC';

        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);

        if ($result) {
            $r['response'] = true;
            $tmp           = [];
            while ($res = $result->fetch_object()) {
                array_push($tmp, $res);
            }
            $r['data'] = $tmp;
        } else {
            $r['response'] = false;
        }

        $this->sendResponse($r);
    }

    /**
     * Create a new silo event.
     *
     * @param array $s $_POST variables (event_date, quantity, remaining, price, event_type)
     */
    public function setEvent(array $s): void
    {
        $r = [];

        $r['response'] = $this->prepare(
            'INSERT INTO oko_silo_events (event_date, quantity, remaining, price, event_type) VALUES (?, ?, ?, ?, ?)',
            [$s['event_date'], $s['quantity'], $s['remaining'], $s['price'], $s['event_type']]
        );

        $this->sendResponse($r);
    }

    /**
     * Update an existing silo event.
     *
     * @param array $s $_POST variables (event_date, quantity, remaining, price, event_type, idEvent)
     */
    public function updateEvent(array $s): void
    {
        $r = [];

        $r['response'] = $this->prepare(
            'UPDATE oko_silo_events SET event_date=?, quantity=?, remaining=?, price=?, event_type=? WHERE id=?',
            [$s['event_date'], $s['quantity'], $s['remaining'], $s['price'], $s['event_type'], (int) $s['idEvent']]
        );

        $this->sendResponse($r);
    }

    /**
     * Delete a silo event.
     *
     * @param array $s $_POST with $s['idEvent']
     */
    public function deleteEvent(array $s): void
    {
        $r             = [];
        $r['response'] = $this->prepare('DELETE FROM oko_silo_events WHERE id=?', [(int) $s['idEvent']]);
        $this->sendResponse($r);
    }
}
