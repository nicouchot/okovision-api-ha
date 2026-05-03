# OKOVISION (fork nicouchot/okovision-ha-api)

Interface web de supervision d'une chaudière Okofen — firmware **V3** (HTML/CSV) et **V4** (API JSON).

## Lignée

- **Projet d'origine** : [stawen/okovision](https://github.com/stawen/okovision) (archivé) — auteur : Stawen Dronek. Doc upstream toujours consultable : <https://okovision.dronek.com/documentation/>
- **Fork parallèle** : [skydarc/okovision_v2](https://github.com/skydarc/okovision_v2) — support V4 initial (mai 2022), plus maintenu.
- **Ce fork** : [nicouchot/okovision-ha-api](https://github.com/nicouchot/okovision-ha-api) — rapatrie les apports V4 de skydarc, cible PHP 8.x / MariaDB 10 / Nginx, intégration Home Assistant prévue.

## Stack

- PHP 8.x (testé 8.2 et 8.4 — voir note IMAP ci-dessous)
- MariaDB 10
- Nginx

## Chantiers réalisés

### Compatibilité firmware 4.00b (v2.0)

Rapatriement des apports V4 du fork [skydarc/okovision_v2](https://github.com/skydarc/okovision_v2).

- Support firmware V4 (API JSON) : config, import, dashboard temps réel (`rt_v4.php`)
- Import CSV via boîte mail IMAP (scripts `_include/bin_v4/*`)
- UI admin : formulaires JSON/mail, routage automatique V3/V4, page `amImpMail.php`
- Reconstruction propre de l'import mail en phase RC (remplace la version provisoire de skydarc)

### Compatibilité PHP 8.4 + correctifs admin (v2.1)

- Remplacement de `utf8_encode()` (supprimée en 8.2) par `mb_convert_encoding()` dans 4 fichiers
- Fix persistance du mode de récupération CSV (`config.php` : coercition booléenne → entier)
- Refonte sous-système IMAP : façade `mail.class.php`, sécurisation des endpoints (session requise, POST), diagnostic d'erreurs structuré JSON avec labels i18n FR/EN
- Note : `ext/imap` retiré du core PHP 8.4 — migration vers librairie userland prévue avant PHP 9

### Sécurité, typage et socle qualité (v2.2)

- Socle qualité : `composer.json`, PHPStan niveau 3, PHP-CS-Fixer PSR-12, `declare(strict_types=1)` sur toutes les classes
- Sécurité : 15+ injections SQL → `prepare()`, helper XSS `e()`, token CSRF `random_bytes(16)`
- Typage complet des classes `_include/*.class.php` (propriétés, paramètres, retours)
- Fix régressions PHP 8.4 sur `rt_v4.php` et `adminParam.php` (spinner bloqué, bouton « Tester » inopérant)

### Refactoring templates temps réel (v2.3)

- Extraction des blocs communs `rt.php` / `rt_v4.php` en helpers partagés (`_templates/rt/`)
- Helper `sensor_panel.php` paramétrable : −611 LOC net (38 panels migrés)
- Modale d'édition capteur + bloc de chargement factorisés

### Refactoring architecture admin (v2.4)

- Éclatement de `administration.class.php` (823 LOC) en 7 classes spécialisées (< 200 LOC chacune)
- Routeur ajax par table de closures (`ajax_routes.php`) : `ajax.php` passe de 340 à 51 LOC
- Fix régressions PHP 8.4 post-refactoring (`sendResponse`, `mktime`, upload matrice, `display_errors`)
- Réorganisation et clarification du menu « Actions Manuelles »

### Rapatriement master non-HA (v2.5)

- Calcul kWh quotidien à partir du PCI pellet et du rendement chaudière (saisis dans adminParam)
- Cumulatifs (kg / kWh / cycles) recalculés à chaque synthèse journalière
- Prix au kg en logique FIFO sur les livraisons (`oko_silo_events`)
- Bouton « Recalculer l'historique » (admin) après changement de PCI/rendement
- Page historique : badges Énergie/Coût mois/saison + colonne « Coût (€) » + série kWh
- Import firmware V4 trois niveaux (log0 24h glissantes / retry log1 / snapshot live `/all?`)
- Détection des cycles depuis la BDD via `recalcStartCycleForDay()` (élimine les faux démarrages minuit)
- Performance : `csv2bdd()` en batch `INSERT IGNORE`
- Migration idempotente (`migrate_v2.php`) pour installs existantes

## Mise à jour vers v2.5.0 (installs existantes)

La v2.5.0 introduit le calcul énergétique (kWh) et le coût pellet (FIFO). Deux étapes manuelles sont **obligatoires** sur toute install antérieure, sinon la page **histo** reste vide et le bouton « Recalculer l'historique » d'**adminParam** renvoie « Erreur lors du recalcul » :

1. **Compléter `config.php`** avec les deux constantes ajoutées en v2.5.0 (cf. `config_sample.php`), juste après le bloc silo / cendrier :

   ```php
   // Calcul énergétique pellet
   DEFINE('PCI_PELLET',          !empty($config['pci_pellet']) ? (float)$config['pci_pellet'] : 4.90); // kWh/kg //json
   DEFINE('RENDEMENT_CHAUDIERE', !empty($config['rendement'])  ? (float)$config['rendement']  : 89.50); // %       //json
   ```

   Sans ces `DEFINE`, PHP 8.x lève une `Error: Undefined constant` qui interrompt le rendu de `adminParam.php` au champ « PCI pellet » (les champs Rendement / Silo / Langue / bouton Recalcul disparaissent).

2. **Lancer `migrate_v2.php` une seule fois** depuis le navigateur (`https://<host>/migrate_v2.php`) ou en CLI. Le script est **idempotent** :
   - `ALTER TABLE oko_resume_day` ajoute les 6 colonnes manquantes (`conso_kwh`, `cumul_kg`, `cumul_kwh`, `cumul_cycle`, `prix_kg`, `prix_kwh`) — colonnes déjà présentes ignorées (errno 1060).
   - Recalcule `conso_kwh` pour les lignes existantes à partir du PCI / rendement courants.
   - Recalcule les cumulatifs (kg / kWh / cycles) sur tout l'historique.
   - Affecte le prix au kg en FIFO d'après les livraisons `oko_silo_events` (event_type = `PELLET`).

   Tant que `migrate_v2.php` n'est pas passé, `getHistoByMonth` (`SELECT conso_kwh ...`) échoue et `recalcHistorique()` lève une `mysqli_sql_exception`.

Le bouton « Recalculer l'historique » dans **adminParam** sert ensuite à rejouer ces calculs dérivés à la demande, notamment **après modification du PCI ou du rendement**.

## Licence

Utilisation commerciale interdite sans accord de l'auteur d'origine (Stawen Dronek).
