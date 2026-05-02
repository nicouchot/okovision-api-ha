<?php
declare(strict_types=1);

/*
 * Migration v2 – Colonnes cumulatives et prix pellet dans oko_resume_day
 * ──────────────────────────────────────────────────────────────────────
 * Nouvelles colonnes :
 *   conso_kwh, cumul_kg, cumul_kwh, cumul_cycle, prix_kg, prix_kwh
 *
 * Idempotent : les colonnes existantes sont ignorées silencieusement.
 * À lancer une seule fois depuis le navigateur ou en CLI après mise à jour.
 */

include_once __DIR__ . '/config.php';

$db = new mysqli(BDD_IP, BDD_USER, BDD_PASS, BDD_SCHEMA);
if ($db->connect_error) {
    die('Erreur de connexion BDD : ' . $db->connect_error);
}
$db->set_charset('utf8');

$pci          = (float) PCI_PELLET;
$rendement    = (float) RENDEMENT_CHAUDIERE;
$energieParKg = $pci * $rendement / 100;

echo '<pre>';
echo '=== Migration v2 – Cumulatifs & prix pellet ===' . PHP_EOL;
echo "PCI pellet : {$pci} kWh/kg  |  Rendement : {$rendement} %" . PHP_EOL . PHP_EOL;

/* ── 1. ALTER TABLE – ajout des colonnes manquantes (idempotent) ─────────── */

$columns = [
    'conso_kwh'   => 'ALTER TABLE oko_resume_day ADD COLUMN conso_kwh   DECIMAL(10,2) NULL DEFAULT NULL AFTER conso_ecs_kg',
    'cumul_kg'    => 'ALTER TABLE oko_resume_day ADD COLUMN cumul_kg    DECIMAL(10,2) NULL DEFAULT NULL AFTER conso_kwh',
    'cumul_kwh'   => 'ALTER TABLE oko_resume_day ADD COLUMN cumul_kwh   DECIMAL(10,2) NULL DEFAULT NULL AFTER cumul_kg',
    'cumul_cycle' => 'ALTER TABLE oko_resume_day ADD COLUMN cumul_cycle INT UNSIGNED  NULL DEFAULT NULL AFTER cumul_kwh',
    'prix_kg'     => 'ALTER TABLE oko_resume_day ADD COLUMN prix_kg     DECIMAL(10,4) NULL DEFAULT NULL AFTER cumul_cycle',
    'prix_kwh'    => 'ALTER TABLE oko_resume_day ADD COLUMN prix_kwh    DECIMAL(10,4) NULL DEFAULT NULL AFTER prix_kg',
];

foreach ($columns as $name => $sql) {
    try {
        $db->query($sql);
        echo "Colonne {$name} : ajoutée" . PHP_EOL;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1060) {
            echo "Colonne {$name} : déjà existante (ignoré)" . PHP_EOL;
        } else {
            die("Erreur ALTER TABLE ({$name}) : " . $e->getMessage());
        }
    }
}

echo PHP_EOL;

/* ── 2. Recalcul de conso_kwh pour les lignes NULL ───────────────────────── */

$sql = "UPDATE oko_resume_day
        SET conso_kwh = ROUND(conso_kg * {$pci} * ({$rendement} / 100), 2)
        WHERE conso_kwh IS NULL AND conso_kg IS NOT NULL";

if (!$db->query($sql)) {
    die('Erreur UPDATE conso_kwh : ' . $db->error);
}
echo 'conso_kwh – lignes recalculées : ' . $db->affected_rows . PHP_EOL . PHP_EOL;

/* ── 3. Calcul des cumulatifs ─────────────────────────────────────────────── */

$result = $db->query(
    'SELECT jour, conso_kg, conso_kwh, nb_cycle
     FROM oko_resume_day
     ORDER BY jour ASC'
);
if (!$result) {
    die('Erreur lecture oko_resume_day : ' . $db->error);
}

$cumulKg    = 0.0;
$cumulKwh   = 0.0;
$cumulCycle = 0;
$updCount   = 0;

while ($r = $result->fetch_object()) {
    $cumulKg    = round($cumulKg    + (float)($r->conso_kg  ?? 0), 2);
    $cumulKwh   = round($cumulKwh   + (float)($r->conso_kwh ?? 0), 2);
    $cumulCycle = $cumulCycle + (int)($r->nb_cycle ?? 0);

    $db->query(
        "UPDATE oko_resume_day
         SET cumul_kg = {$cumulKg}, cumul_kwh = {$cumulKwh}, cumul_cycle = {$cumulCycle}
         WHERE jour = '{$r->jour}'"
    );
    if ($db->errno) {
        die("Erreur UPDATE cumul pour {$r->jour} : " . $db->error);
    }
    ++$updCount;
}

echo "Cumulatifs – {$updCount} lignes mises à jour." . PHP_EOL . PHP_EOL;

/* ── 4. Chargement des livraisons PELLET (FIFO) ──────────────────────────── */

$lotResult = $db->query(
    "SELECT event_date, quantity, price
     FROM oko_silo_events
     WHERE event_type = 'PELLET' AND quantity > 0
     ORDER BY event_date ASC"
);
if (!$lotResult) {
    die('Erreur lecture oko_silo_events : ' . $db->error);
}

$lots           = [];
$cumulLivraison = 0;

while ($l = $lotResult->fetch_object()) {
    $cumulLivraison += (int)$l->quantity;
    $lots[] = [
        'prix_kg'         => round((float)$l->price / (int)$l->quantity, 4),
        'cumul_livraison' => $cumulLivraison,
    ];
}

echo 'Livraisons PELLET trouvées : ' . count($lots) . PHP_EOL;

if (empty($lots)) {
    echo 'Aucune livraison PELLET – colonnes prix_kg et prix_kwh non renseignées.' . PHP_EOL;
} else {
    /* ── 5. Affectation du prix par jour (FIFO) ──────────────────────────── */

    $lastPrixKg   = $lots[count($lots) - 1]['prix_kg'];
    $rowsForPrice = $db->query(
        'SELECT jour, cumul_kg FROM oko_resume_day WHERE cumul_kg IS NOT NULL ORDER BY jour ASC'
    );
    if (!$rowsForPrice) {
        die('Erreur lecture cumul_kg : ' . $db->error);
    }

    $prixCount = 0;

    while ($r = $rowsForPrice->fetch_object()) {
        $cumulKg = (float)$r->cumul_kg;
        $prixKg  = $lastPrixKg;

        foreach ($lots as $lot) {
            if ($lot['cumul_livraison'] >= $cumulKg) {
                $prixKg = $lot['prix_kg'];
                break;
            }
        }

        $prixKwh    = ($energieParKg > 0) ? round($prixKg / $energieParKg, 4) : null;
        $prixKwhSql = ($prixKwh !== null) ? (string)$prixKwh : 'NULL';

        $db->query(
            "UPDATE oko_resume_day
             SET prix_kg = {$prixKg}, prix_kwh = {$prixKwhSql}
             WHERE jour = '{$r->jour}'"
        );
        if ($db->errno) {
            die("Erreur UPDATE prix pour {$r->jour} : " . $db->error);
        }
        ++$prixCount;
    }

    echo "Prix – {$prixCount} lignes mises à jour." . PHP_EOL;
}

echo PHP_EOL . '=== Migration v2 terminée. ===' . PHP_EOL;
echo '</pre>';
