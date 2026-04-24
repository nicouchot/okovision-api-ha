<?php

declare(strict_types=1);

/*
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
*/

class capteur extends connectDb
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    public function getAll(): array
    {
        $rows = [];
        $result = $this->query('SELECT id, name, position_column_csv, column_oko, original_name, type FROM oko_capteur');
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function get(int $id): array
    {
        $result = $this->prepare(
            'SELECT id, name, position_column_csv, column_oko, original_name, type FROM oko_capteur WHERE id=?',
            [$id]
        );

        if ($result instanceof \mysqli_result) {
            return (array) $result->fetch_assoc();
        }

        return [];
    }

    public function getForImportCsv(): array
    {
        $r = [];
        $result = $this->query('SELECT id, name, position_column_csv, column_oko, original_name, type FROM oko_capteur WHERE position_column_csv <> -1');
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $r[$row['position_column_csv']] = $row;
            }
        }

        return $r;
    }

    public function getMatrix(): array
    {
        $r = [];
        $result = $this->query("SELECT id, name, position_column_csv, column_oko, original_name, type FROM oko_capteur WHERE type <> 'startCycle' ORDER BY position_column_csv ASC");
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_object()) {
                $r[$row->original_name] = $row;
            }
        }

        return $r;
    }

    public function getByType(string $type = ''): ?array
    {
        if ($type === '') {
            return null;
        }

        $result = $this->prepare(
            'SELECT id, name, position_column_csv, column_oko, original_name, type FROM oko_capteur WHERE type=?',
            [$type]
        );

        if ($result instanceof \mysqli_result) {
            return $result->fetch_assoc();
        }

        return null;
    }

    public function getLastColumnOko(): int
    {
        $result = $this->query('SELECT MAX(column_oko) AS num FROM oko_capteur');
        if ($result instanceof \mysqli_result) {
            $r = $result->fetch_object();
            $this->log->debug('Class '.__CLASS__.' | '.__FUNCTION__.' | '.$r->num);

            return (int) $r->num;
        }

        return 0;
    }
}
