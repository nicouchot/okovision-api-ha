<?php
/*
 * Migration : ajout de la colonne conso_kwh dans oko_resume_day
 * Lancer une seule fois depuis un navigateur ou en CLI :
 *   php install/migrate_conso_kwh.php
 *
 * La valeur est calculée ainsi :
 *   conso_kwh = conso_kg × PCI_PELLET × (RENDEMENT_CHAUDIERE / 100)
 */

include_once __DIR__ . '/config.php';

$db = new mysqli(BDD_IP, BDD_USER, BDD_PASS, BDD_SCHEMA);
if ($db->connect_error) {
    die('Erreur de connexion BDD : ' . $db->connect_error . PHP_EOL);
}
$db->set_charset('utf8');

echo '=== Migration conso_kwh ===' . PHP_EOL;
echo 'PCI pellet     : ' . PCI_PELLET . ' kWh/kg' . PHP_EOL;
echo 'Rendement      : ' . RENDEMENT_CHAUDIERE . ' %' . PHP_EOL . PHP_EOL;

// 1. Ajout de la colonne (ignoré si elle existe déjà)
$db->query('ALTER TABLE oko_resume_day ADD COLUMN conso_kwh DECIMAL(10,2) NULL DEFAULT NULL AFTER conso_ecs_kg');
if ($db->errno && $db->errno !== 1060) {   // 1060 = Duplicate column name
    die('Erreur ALTER TABLE : ' . $db->error . PHP_EOL);
}
$colMsg = ($db->errno === 1060) ? 'déjà existante (ignoré)' : 'ajoutée';
echo "Colonne conso_kwh : {$colMsg}" . PHP_EOL;

// 2. Calcul et mise à jour de toutes les lignes existantes
$pci       = (float) PCI_PELLET;
$rendement = (float) RENDEMENT_CHAUDIERE;

$sql = "UPDATE oko_resume_day
        SET conso_kwh = ROUND(conso_kg * {$pci} * ({$rendement} / 100), 2)
        WHERE conso_kwh IS NULL AND conso_kg IS NOT NULL";

if (!$db->query($sql)) {
    die('Erreur UPDATE : ' . $db->error . PHP_EOL);
}

echo 'Lignes mises à jour : ' . $db->affected_rows . PHP_EOL;
echo PHP_EOL . 'Migration terminée.' . PHP_EOL;
