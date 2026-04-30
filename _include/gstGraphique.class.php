<?php

declare(strict_types=1);

class gstGraphique extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function getGraphe(): void
    {
        $result = $this->query('SELECT id, name, position FROM oko_graphe ORDER BY position');

        $r = ['response' => false];
        if ($result instanceof \mysqli_result) {
            $tmp = [];
            while ($res = $result->fetch_object()) {
                $tmp[] = $res;
            }
            $r = ['response' => true, 'data' => $tmp];
        }

        $this->sendResponse($r);
    }

    public function getLastGraphePosition(): void
    {
        $q = 'SELECT MAX(position) AS lastPosition FROM oko_graphe';
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);
        $r = ['response' => false];

        if ($result instanceof \mysqli_result) {
            $r = ['response' => true, 'data' => $result->fetch_object()];
        }

        $this->sendResponse($r);
    }

    public function grapheNameExist(string $name): void
    {
        $result = $this->prepare('SELECT COUNT(*) FROM oko_graphe WHERE name=?', [$name]);

        $r = ['exist' => false];
        if ($result instanceof \mysqli_result) {
            $res = $result->fetch_row();
            if ($res[0] > 0) {
                $r['exist'] = true;
            }
        }

        $this->sendResponse($r);
    }

    public function addGraphe(array $s): void
    {
        $r = ['response' => $this->prepare(
            "INSERT INTO oko_graphe (name, position) VALUES (?, ?)",
            [$s['name'], (int) $s['position']]
        ) !== false];

        $this->sendResponse($r);
    }

    public function updateGraphe(array $s): void
    {
        $r = ['response' => $this->prepare(
            'UPDATE oko_graphe SET name=? WHERE id=?',
            [$s['name'], (int) $s['id']]
        ) !== false];

        $this->sendResponse($r);
    }

    public function updateGraphePosition(array $s): void
    {
        $r = ['response' => false];

        if ($this->prepare('UPDATE oko_graphe SET position=? WHERE id=?', [(int) $s['position'], (int) $s['id_graphe']])) {
            if ((int) $s['position'] > (int) $s['current']) {
                $ok = $this->prepare(
                    'UPDATE oko_graphe SET position=(position - 1) WHERE position <= ? AND position > ? AND id <> ?',
                    [(int) $s['position'], (int) $s['current'], (int) $s['id_graphe']]
                );
            } else {
                $ok = $this->prepare(
                    'UPDATE oko_graphe SET position=(position + 1) WHERE position >= ? AND position < (? + 1) AND id <> ?',
                    [(int) $s['position'], (int) $s['current'], (int) $s['id_graphe']]
                );
            }
            $r['response'] = !empty($ok);
        }

        $this->sendResponse($r);
    }

    public function deleteGraphe(array $s): void
    {
        $r = ['response' => false];

        $result = $this->prepare('SELECT position FROM oko_graphe WHERE id=?', [(int) $s['id']]);

        if ($result instanceof \mysqli_result) {
            $res = $result->fetch_object();
            $position = (int) $res->position;

            if ($this->prepare('DELETE FROM oko_graphe WHERE id=?', [(int) $s['id']])) {
                $ok = $this->prepare(
                    'UPDATE oko_graphe SET position=(position - 1) WHERE position > ?',
                    [$position]
                );
                $r['response'] = !empty($ok);
            }
        }

        $this->sendResponse($r);
    }

    public function getCapteurs(): void
    {
        $q = 'SELECT id, name FROM oko_capteur ORDER BY id ASC';
        $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$q);

        $result = $this->query($q);
        $r = ['response' => false];

        if ($result instanceof \mysqli_result) {
            $tmp = [];
            while ($res = $result->fetch_object()) {
                $tmp[] = $res;
            }
            $r = ['response' => true, 'data' => $tmp];
        }

        $this->sendResponse($r);
    }

    public function grapheAssoCapteurExist(int $graphe, int $capteur): void
    {
        $result = $this->prepare(
            'SELECT COUNT(*) FROM oko_asso_capteur_graphe WHERE oko_graphe_id=? AND oko_capteur_id=?',
            [$graphe, $capteur]
        );

        $r = ['exist' => false];
        if ($result instanceof \mysqli_result) {
            $res = $result->fetch_row();
            if ($res[0] > 0) {
                $r['exist'] = true;
            }
        }

        $this->sendResponse($r);
    }

    public function addGrapheAsso(array $s): void
    {
        $r = ['response' => $this->prepare(
            'INSERT INTO oko_asso_capteur_graphe (oko_graphe_id, oko_capteur_id, position, correction_effect) VALUES (?, ?, ?, ?)',
            [(int) $s['id_graphe'], (int) $s['id_capteur'], (int) $s['position'], (float) $s['coeff']]
        ) !== false];

        $this->sendResponse($r);
    }

    public function getGrapheAsso(int $grapheId): void
    {
        $result = $this->prepare(
            'SELECT capteur.id, capteur.name, asso.correction_effect AS coeff FROM oko_asso_capteur_graphe AS asso LEFT JOIN oko_capteur AS capteur ON asso.oko_capteur_id = capteur.id WHERE asso.oko_graphe_id=? ORDER BY asso.position',
            [$grapheId]
        );

        $r = ['response' => false];
        if ($result instanceof \mysqli_result) {
            $tmp = [];
            while ($res = $result->fetch_object()) {
                $tmp[] = $res;
            }
            $r = ['response' => true, 'data' => $tmp];
        }

        $this->sendResponse($r);
    }

    public function updateGrapheAsso(array $s): void
    {
        $r = ['response' => $this->prepare(
            'UPDATE oko_asso_capteur_graphe SET correction_effect=? WHERE oko_graphe_id=? AND oko_capteur_id=?',
            [(float) $s['coeff'], (int) $s['id_graphe'], (int) $s['id_capteur']]
        ) !== false];

        $this->sendResponse($r);
    }

    public function updateGrapheAssoPosition(array $s): void
    {
        $r = ['response' => false];

        if ($this->prepare(
            'UPDATE oko_asso_capteur_graphe SET position=? WHERE oko_graphe_id=? AND oko_capteur_id=?',
            [(int) $s['position'], (int) $s['id_graphe'], (int) $s['id_capteur']]
        )) {
            if ((int) $s['position'] > (int) $s['current']) {
                $ok = $this->prepare(
                    'UPDATE oko_asso_capteur_graphe SET position=(position - 1) WHERE position <= ? AND position > ? AND oko_graphe_id=? AND oko_capteur_id <> ?',
                    [(int) $s['position'], (int) $s['current'], (int) $s['id_graphe'], (int) $s['id_capteur']]
                );
            } else {
                $ok = $this->prepare(
                    'UPDATE oko_asso_capteur_graphe SET position=(position + 1) WHERE position >= ? AND position < (? + 1) AND oko_graphe_id=? AND oko_capteur_id <> ?',
                    [(int) $s['position'], (int) $s['current'], (int) $s['id_graphe'], (int) $s['id_capteur']]
                );
            }
            $r['response'] = !empty($ok);
        }

        $this->sendResponse($r);
    }

    public function deleteAssoGraphe(array $s): void
    {
        $r = ['response' => false];

        $result = $this->prepare(
            'SELECT position FROM oko_asso_capteur_graphe WHERE oko_graphe_id=? AND oko_capteur_id=?',
            [(int) $s['id_graphe'], (int) $s['id_capteur']]
        );

        if ($result instanceof \mysqli_result) {
            $res = $result->fetch_object();
            $position = (int) $res->position;

            if ($this->prepare(
                'DELETE FROM oko_asso_capteur_graphe WHERE oko_graphe_id=? AND oko_capteur_id=?',
                [(int) $s['id_graphe'], (int) $s['id_capteur']]
            )) {
                $ok = $this->prepare(
                    'UPDATE oko_asso_capteur_graphe SET position=(position - 1) WHERE position > ? AND oko_graphe_id=?',
                    [$position, (int) $s['id_graphe']]
                );
                $r['response'] = !empty($ok);
            }
        }

        $this->sendResponse($r);
    }
}
