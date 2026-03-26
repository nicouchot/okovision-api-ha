# OkoVision – API Home Assistant & améliorations v2

Ce dépôt contient l'ensemble des modifications apportées à [OkoVision](https://github.com/stawen/okovision)
pour l'intégration avec **Home Assistant**, le calcul de la consommation énergétique en kWh,
et des corrections de compatibilité PHP 8.2.

> **Intégration Home Assistant (HACS)**
> L'intégration HACS compatible avec l'API exposée par ce dépôt est disponible ici :
> **[nicouchot/okovision-ha](https://github.com/nicouchot/okovision-ha)**

---

## Sommaire

1. [Corrections PHP 8.2](#1-corrections-php-82)
2. [Nouveaux paramètres admin](#2-nouveaux-paramètres-admin)
3. [Calcul kWh et données dérivées](#3-calcul-kwh-et-données-dérivées)
4. [Données cumulatives et prix du pellet](#4-données-cumulatives-et-prix-du-pellet)
5. [Affichage dans les graphiques (histo.php)](#5-affichage-dans-les-graphiques-histophp)
6. [API Home Assistant (ha_api.php)](#6-api-home-assistant-ha_apiphp)
7. [Migration de la base existante](#7-migration-de-la-base-existante)
8. [Référence des champs de l'API](#8-référence-des-champs-de-lapi)

---

## 1. Corrections PHP 8.2

### Clé de tableau indéfinie dans `saveInfoGenerale()`

**Fichier :** `_include/administration.class.php`

PHP 8.x émet un `E_Warning` quand une clé de tableau `$_POST` n'existe pas. Avec
`display_errors = On` (courant sur Synology), ce warning s'affiche avant le `header()`
et corrompt la réponse JSON — le front affichait *"Problème de communication : admin.saveInfoGe"*.

**Correction :** opérateur `??` sur tous les accès `$s[]` potentiellement absents :

```php
// Avant (PHP 8 warning → JSON corrompu)
'pci_pellet' => $s['pci_pellet'],
'rendement'  => $s['rendement'],

// Après
'pci_pellet' => $s['pci_pellet'] ?? '',
'rendement'  => $s['rendement']  ?? '',
```

---

## 2. Nouveaux paramètres admin

**Fichiers :** `adminParam.php`, `js/adminParam.js`, `config_sample.php`

Deux nouvelles valeurs configurables dans la page **Paramètres** :

| Paramètre | Constante PHP | Défaut |
|-----------|--------------|--------|
| Pouvoir Calorifique Inférieur du pellet (kWh/kg) | `PCI_PELLET` | `4.90` |
| Rendement de la chaudière (%) | `RENDEMENT_CHAUDIERE` | `89.50` |

Stockées dans `config.json`, lues via `config_sample.php` → `config.php`.

Un fieldset **Token API Home Assistant** a également été ajouté pour afficher le token
d'authentification à 12 caractères et permettre sa régénération sans toucher aux fichiers.

---

## 3. Calcul kWh et données dérivées

**Fichiers :** `_include/okofen.class.php`, `_include/rendu.class.php`, `install/install.sql`

### Formule

```
conso_kwh = conso_kg × PCI_PELLET × (RENDEMENT_CHAUDIERE / 100)
```

### Colonne ajoutée dans `oko_resume_day`

```sql
`conso_kwh` decimal(10,2) DEFAULT NULL
```

La méthode `insertSyntheseDay()` calcule et persiste `conso_kwh` à chaque synthèse quotidienne.

---

## 4. Données cumulatives et prix du pellet

**Fichiers :** `_include/okofen.class.php`, `install/install.sql`

### Colonnes ajoutées dans `oko_resume_day`

```sql
`cumul_kg`    decimal(10,2) DEFAULT NULL,  -- pellet cumulé depuis le 1er jour
`cumul_kwh`   decimal(10,2) DEFAULT NULL,  -- énergie cumulée depuis le 1er jour
`cumul_cycle` int unsigned  DEFAULT NULL,  -- cycles cumulés depuis le 1er jour
`prix_kg`     decimal(10,4) DEFAULT NULL,  -- prix du lot actif (€/kg)
`prix_kwh`    decimal(10,4) DEFAULT NULL   -- prix de l'énergie utile (€/kWh)
```

### Logique FIFO pour le prix au kg

Les livraisons de pellets (événements `PELLET` dans `oko_silo_events`) sont triées par date.
La consommation cumulée est comparée aux quantités livrées cumulées :

```
Livraison 1 : 1 000 kg à 300 € → 0,30 €/kg
  → jours dont cumul_kg ∈ [0, 1 000] → prix_kg = 0,30

Livraison 2 : 800 kg à 256 € → 0,32 €/kg
  → jours dont cumul_kg ∈ [1 001, 1 800] → prix_kg = 0,32
```

Le prix par kWh est dérivé :
```
prix_kwh = prix_kg / (PCI_PELLET × RENDEMENT_CHAUDIERE / 100)
```

---

## 5. Affichage dans les graphiques (histo.php)

**Fichiers :** `histo.php`, `js/histo.js`

- Badge **Énergie (kWh)** ajouté au-dessus du graphique mensuel et du graphique de synthèse saison.
- Colonne **Énergie (kWh)** dans le tableau mensuel de récapitulatif.
- Série kWh affichée en **colonnes oranges** (`#FF6B35`) sur un axe Y dédié dans les deux graphiques
  (mensuel et saison), avec labels blancs à −90°.

---

## 6. API Home Assistant (`ha_api.php`)

Endpoint REST dédié à l'intégration Home Assistant. Authentification par token (12 premiers
caractères de la constante `TOKEN`).

### Authentification

```
GET https://votre-okovision/ha_api.php?token=XXXXXXXXXXXX&action=…
```

Le token est visible dans **Admin → Paramètres → Token API Home Assistant**.

### Actions disponibles

| Action | Paramètres | Description |
|--------|-----------|-------------|
| `today` | — | Données live du jour en cours + silo + cendrier + maintenance |
| `daily` | `date=YYYY-MM-DD` | Résumé archivé d'une journée précise |
| `monthly` | `month=MM&year=YYYY` | Tous les jours du mois + totaux |
| `status` | — | État silo + cendrier + maintenance (sans données de consommation) |

### Exemple — données du jour (`today`)

```bash
curl "https://votre-okovision/ha_api.php?token=XXXXXXXXXXXX&action=today"
```

```json
{
    "date": "2026-03-26",
    "dju": 9.5,
    "conso_kg": 5.20,
    "conso_ecs_kg": 0,
    "conso_kwh": 24.19,
    "cumul_kg": 6891.0,
    "cumul_kwh": 32084.5,
    "cumul_cycle": 6268,
    "prix_kg": 0.36,
    "prix_kwh": 0.0773,
    "nb_cycle": 7,
    "tc_ext_max": 10.2,
    "tc_ext_min": 3.1,
    "silo": {
        "remains_kg": 155,
        "capacity_kg": 3500,
        "percent": 4,
        "last_fill_date": "2025-05-23"
    },
    "ashtray": {
        "remains_kg": 62.0,
        "capacity_kg": 800,
        "percent": 92,
        "needs_emptying": false,
        "last_empty_date": "2026-01-18"
    },
    "maintenance": {
        "last_sweep": "2026-01-19",
        "last_maintenance": "2025-11-26"
    }
}
```

---

## 7. Migration de la base existante

### Installation sur une base vierge

Le schéma dans `install/install.sql` est à jour et inclut toutes les colonnes.

### Migration d'une installation existante

Exécuter les scripts depuis le navigateur (une seule fois) :

**Étape 1** — Ajout de `conso_kwh` (si pas encore fait) :
```
https://votre-okovision/migrate_conso_kwh.php
```

**Étape 2** — Ajout des colonnes cumulatives et prix (idempotent) :
```
https://votre-okovision/migrate_v2.php
```

`migrate_v2.php` est **idempotent** : il peut être relancé sans risque.
Les colonnes déjà existantes sont ignorées, les données sont recalculées proprement.

---

## 8. Référence des champs de l'API

### Champs journaliers (actions `today` et `daily`)

| Champ | Type | Description |
|-------|------|-------------|
| `date` | `string` (YYYY-MM-DD) | Date de la journée |
| `dju` | `float` | Degrés Jours Unifiés (confort 19 °C) |
| `conso_kg` | `float` | Consommation pellet du jour (kg) |
| `conso_ecs_kg` | `float` | Consommation pellet ECS du jour (kg) |
| `conso_kwh` | `float` | Énergie produite du jour (kWh) |
| `cumul_kg` | `float` | Consommation pellet cumulée depuis le 1er enregistrement (kg) |
| `cumul_kwh` | `float` | Énergie cumulée depuis le 1er enregistrement (kWh) |
| `cumul_cycle` | `int` | Nombre de cycles cumulés depuis le 1er enregistrement |
| `prix_kg` | `float` | Prix du lot de pellet actif — logique FIFO (€/kg) |
| `prix_kwh` | `float` | Prix de l'énergie utile (€/kWh) |
| `nb_cycle` | `int` | Nombre de cycles chaudière du jour |
| `tc_ext_max` | `float` | Température extérieure max du jour (°C) |
| `tc_ext_min` | `float` | Température extérieure min du jour (°C) |

### Champs silo (inclus dans `today` et `status`)

| Champ | Type | Description |
|-------|------|-------------|
| `silo.remains_kg` | `float` | Pellet restant estimé dans le silo (kg) |
| `silo.capacity_kg` | `float` | Capacité totale du silo (kg) |
| `silo.percent` | `int` | Taux de remplissage (%) |
| `silo.last_fill_date` | `string` | Date du dernier remplissage (YYYY-MM-DD) |

### Champs cendrier (inclus dans `today` et `status`)

| Champ | Type | Description |
|-------|------|-------------|
| `ashtray.remains_kg` | `float` | Capacité restante avant vidange (kg) |
| `ashtray.capacity_kg` | `float` | Capacité totale du bac à cendres (kg) |
| `ashtray.percent` | `int` | Taux de remplissage du cendrier (%) |
| `ashtray.needs_emptying` | `bool` | `true` si le cendrier est plein |
| `ashtray.last_empty_date` | `string` | Date du dernier vidage (YYYY-MM-DD) |

### Champs maintenance (inclus dans `today` et `status`)

| Champ | Type | Description |
|-------|------|-------------|
| `maintenance.last_sweep` | `string\|null` | Date du dernier ramonage (YYYY-MM-DD) |
| `maintenance.last_maintenance` | `string\|null` | Date du dernier entretien (YYYY-MM-DD) |

### Action `monthly` — structure de réponse

```json
{
    "month": 3,
    "year": 2026,
    "totals": {
        "dju": 210.5,
        "conso_kg": 180.3,
        "conso_ecs_kg": 12.1,
        "conso_kwh": 839.0,
        "nb_cycle": 215,
        "tc_ext_max": 14.2,
        "tc_ext_min": -3.5
    },
    "days": [
        {
            "date": "2026-03-01",
            "dju": 11.2,
            "conso_kg": 7.80,
            "conso_ecs_kg": 0,
            "conso_kwh": 36.29,
            "cumul_kg": 6723.4,
            "cumul_kwh": 31290.5,
            "cumul_cycle": 6110,
            "prix_kg": 0.36,
            "prix_kwh": 0.0773,
            "nb_cycle": 10,
            "tc_ext_max": 7.5,
            "tc_ext_min": 1.2
        }
    ]
}
```

---

## Fichiers modifiés / ajoutés

| Fichier | Statut | Description |
|---------|--------|-------------|
| `ha_api.php` | **Nouveau** | API REST Home Assistant |
| `migrate_conso_kwh.php` | **Nouveau** | Migration v1 — colonne `conso_kwh` |
| `migrate_v2.php` | **Nouveau** | Migration v2 — colonnes cumulatives et prix |
| `config_sample.php` | Modifié | Constantes `PCI_PELLET` et `RENDEMENT_CHAUDIERE` |
| `adminParam.php` | Modifié | Champs PCI/rendement + affichage token HA |
| `js/adminParam.js` | Modifié | Sauvegarde PCI/rendement + régénération token |
| `ajax.php` | Modifié | Route `regenerateToken` |
| `histo.php` | Modifié | Badges kWh + colonne tableau mensuel |
| `js/histo.js` | Modifié | Série kWh en colonnes dans les graphiques |
| `_include/administration.class.php` | Modifié | Fix PHP 8.2 + méthode `regenerateToken()` |
| `_include/okofen.class.php` | Modifié | `insertSyntheseDay()` — calcul complet |
| `_include/rendu.class.php` | Modifié | kWh dans synthèses mensuelles et saisonnières |
| `install/install.sql` | Modifié | Schéma `oko_resume_day` complet |
