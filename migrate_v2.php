<?php
/*
 * Migration v2 – Données cumulatives et prix du pellet par jour
 * ─────────────────────────────────────────────────────────────
 * Nouvelles colonnes dans oko_resume_day :
 *   conso_kwh   – consommation journalière en kWh
 *   cumul_kg    – consommation cumulée en kg depuis le 1er jour de la base
 *   cumul_kwh   – consommation cumulée en kWh depuis le 1er jour de la base
 *   cumul_cycle – nombre de cycles cumulés depuis le 1er jour de la base
 *   prix_kg     – prix au kg du lot en cours (logique FIFO sur les livraisons PELLET)
 *   prix_kwh    – prix au kWh = prix_kg / (PCI_PELLET × RENDEMENT / 100)
 *
 * Logique prix FIFO :
 *   Les livraisons PELLET sont triées par date.
 *   La consommation cumulée est comparée aux quantités livrées cumulées.
 *   Ex : livraison 1 = 1 000 kg → les 1 000 premiers kg consommés ont le prix du lot 1.
 *        livraison 2 = 800 kg  → les kg suivants (1 001–1 800) ont le prix du lot 2.
 *
 * Lancer une seule fois depuis le navigateur ou en CLI :
 *   php migrate_v2.php
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

$timeStart = microtime(true);

echo '<pre>';
echo '=== Migration v2 – Cumulatifs & prix ===' . PHP_EOL;
echo "PCI pellet : {$pci} kWh/kg  |  Rendement : {$rendement} %" . PHP_EOL . PHP_EOL;

/* ── 1. ALTER TABLE – ajout des colonnes manquantes ─────────────────────── */

$columns = [
    'conso_kwh'   => 'ALTER TABLE oko_resume_day ADD COLUMN conso_kwh  DECIMAL(10,2) NULL DEFAULT NULL AFTER conso_ecs_kg',
    'cumul_kg'    => 'ALTER TABLE oko_resume_day ADD COLUMN cumul_kg   DECIMAL(10,2) NULL DEFAULT NULL AFTER conso_kwh',
    'cumul_kwh'   => 'ALTER TABLE oko_resume_day ADD COLUMN cumul_kwh  DECIMAL(10,2) NULL DEFAULT NULL AFTER cumul_kg',
    'cumul_cycle' => 'ALTER TABLE oko_resume_day ADD COLUMN cumul_cycle INT UNSIGNED  NULL DEFAULT NULL AFTER cumul_kwh',
    'prix_kg'     => 'ALTER TABLE oko_resume_day ADD COLUMN prix_kg    DECIMAL(10,4) NULL DEFAULT NULL AFTER cumul_cycle',
    'prix_kwh'    => 'ALTER TABLE oko_resume_day ADD COLUMN prix_kwh   DECIMAL(10,4) NULL DEFAULT NULL AFTER prix_kg',
];

foreach ($columns as $name => $sql) {
    $db->query($sql);
    if ($db->errno && $db->errno !== 1060) {
        die("Erreur ALTER TABLE ({$name}) : " . $db->error);
    }
    $msg = ($db->errno === 1060) ? 'déjà existante (ignoré)' : 'ajoutée';
    echo "Colonne {$name} : {$msg}" . PHP_EOL;
}

echo PHP_EOL;

/* ── 2. Recalcul de conso_kwh pour les lignes NULL ──────────────────────── */

$sql = "UPDATE oko_resume_day
        SET conso_kwh = ROUND(conso_kg * {$pci} * ({$rendement} / 100), 2)
        WHERE conso_kwh IS NULL AND conso_kg IS NOT NULL";

if (!$db->query($sql)) {
    die('Erreur UPDATE conso_kwh : ' . $db->error);
}
echo 'conso_kwh – lignes recalculées : ' . $db->affected_rows . PHP_EOL . PHP_EOL;

/* ── 3. Calcul des cumulatifs (lecture séquentielle par date ASC) ────────── */

$result = $db->query(
    "SELECT jour, conso_kg, conso_kwh, nb_cycle
     FROM oko_resume_day
     ORDER BY jour ASC"
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
    $updCount++;
}

echo "Cumulatifs – {$updCount} lignes mises à jour." . PHP_EOL . PHP_EOL;

/* ── 4. Chargement des livraisons PELLET (FIFO) ─────────────────────────── */

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

    $lastPrixKg = $lots[count($lots) - 1]['prix_kg'];   // fallback = dernier lot

    $rowsForPrice = $db->query(
        "SELECT jour, cumul_kg FROM oko_resume_day WHERE cumul_kg IS NOT NULL ORDER BY jour ASC"
    );
    if (!$rowsForPrice) {
        die('Erreur lecture cumul_kg : ' . $db->error);
    }

    $prixCount = 0;

    while ($r = $rowsForPrice->fetch_object()) {
        $cumulKg = (float)$r->cumul_kg;
        $prixKg  = $lastPrixKg;   // défaut : dernier lot

        foreach ($lots as $lot) {
            if ($lot['cumul_livraison'] >= $cumulKg) {
                $prixKg = $lot['prix_kg'];
                break;
            }
        }

        $prixKwh    = ($energieParKg > 0) ? round($prixKg / $energieParKg, 4) : null;
        $prixKwhSql = ($prixKwh !== null) ? $prixKwh : 'NULL';

        $db->query(
            "UPDATE oko_resume_day
             SET prix_kg = {$prixKg}, prix_kwh = {$prixKwhSql}
             WHERE jour = '{$r->jour}'"
        );
        if ($db->errno) {
            die("Erreur UPDATE prix pour {$r->jour} : " . $db->error);
        }
        $prixCount++;
    }

    echo "Prix – {$prixCount} lignes mises à jour." . PHP_EOL;
}

$elapsed = round(microtime(true) - $timeStart, 2);
echo PHP_EOL . "=== Migration v2 terminée en {$elapsed}s. ===" . PHP_EOL;
echo '</pre>';
