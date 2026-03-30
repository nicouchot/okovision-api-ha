- /admin

  - /testip : test if boiler respond

  - /saveInfoGe : Save Generals Configuration

  - /getFileFromChaudiere : Get List of file avalaible on Boiler

  - /importFileFromChaudiere : Import into db with url file from boiler
  - /uploadCsv : Methode how upload CSV file into /tmp and rename it.
  - /getHeaderFromOkoCsv : Get All sensor in oko_capteur and format it into json for page Matrix
  - /statusMatrice : Test if matrix has been initiate or not.
  - /deleteMatrice : Delete all row in oko_capteur and flush all data day. But not data history.
  - /importcsv : Force import csv into db, but it's doesn't download a new file.

  - /getSaisons : get list season created into season table
  - /existSaison : Test if this date is the first date of a season
  - /setSaison : Record a new season
  - /deleteSaison : Delete season
  - /updateSaison : Update Season

  - /getEvents : Get storage Tank Event
  - /setEvent : Set storage Tank Event
  - /deleteEvent : Delete storage Tank Event
  - /updateEvent : Update storage Tank Event

  - /makeSyntheseByDay : Force Synthese for one day
  - /getDayWithoutSynthese : Detect all day who have data but not a resume day

  - /getFileFromTmp : Function return all file in \_tmp/ folder
  - /importFileFromTmp : Function importing boiler file from \_tmp/ folder

- /graphique

  - getLastGraphePosition : Get Last Position Number info oko_graphe
  - grapheNameExist : Test if graphe Name already exist
  - addGraphe : Add graphe name info oko_graphe
  - getGraphe : Get graphe property from oko_graphe
  - updateGraphe : Update graphe properties from oko_graphe
  - updateGraphePosition : Update graphe property 'Position' info oko_graphe
  - deleteGraphe : Delete all propertie for a specific graphe

  - getCapteurs : Get List of all sensor info oko_capteurs
  - grapheAssoCapteurExist : Return true if Sensor is already in graphe
  - addGrapheAsso : Insert into oko_asso_capteur_graphe association between graphic, sensor and Sensor correction effect
  - getGrapheAsso : Get Sensor associate for an Graphe in predifined order
  - updateGrapheAsso : Update sensor position into graphic
  - deleteAssoGraphe : Delete association between graphic and sensor

- /rendu

  - getGraphe : Get graphe list in order by position
  - getGrapheData : By Id and by day, get data for a graphe
  - getIndicByDay : Get Indicator for one Day (pellet kg, hot water comsuption, T°c Max and Min)
  - getIndicByMonth : Get T°c ext max/min; comsuption pellet (and HW), dju and cycle number for all day in a month
  - getStockStatus : get pellet stock remains in storage tank or bag (% and kg)
  - getAshtrayStatus : Say if Ashtray must be clean
  - getHistoByMonth : Get T°c ext max/min; comsuption pellet (and HW), dju and cycle number resumed for a month
  - getTotalSaison : Get T°c ext max/min; comsuption pellet (and HW), dju and cycle number resumed for a season
  - getSyntheseSaison : Get Synthetic data for a complete season, agregat by month for graphic render
  - getSyntheseSaisonTable : Same as getSyntheseSaison but for table render
  - getAnnotationByDay : get into oko_boiler change configuration, show it with a bar in daily chart

- /rt
  - getIndic : Get Sensor Values from determinated sensor List
  - setOkoLogin : save boiler login/password
  - getData : get all data fro mboiler for a specific chart
  - getSensorInfo : get value for one boiler sensor
  - saveBoilerConfig : Save boiler config on db
  - getListConfigBoiler : Get boiler config from db
  - deleteConfigBoiler : Delete boiler config from db
  - getConfigBoiler : get specific config for db for load into page
  - applyBoilerConfig : Apply config on boiler

---

## API Home Assistant (`ha_api.php`)

Endpoint dédié à l'intégration Home Assistant.
**Authentification :** `?token=XXXX` (12 premiers caractères de TOKEN dans config.php)

### `?action=today`

Données live du jour en cours calculées depuis `oko_historique_full`, complétées par l'état du silo, du cendrier et de la maintenance.

| Champ | Type | Description |
|---|---|---|
| `date` | string | Date du jour `YYYY-MM-DD` |
| `dju` | float | Degrés-Jours Unifiés du jour (0 si températures pas encore disponibles) |
| `conso_kg` | float | Consommation pellet du jour (kg) |
| `conso_ecs_kg` | float | Dont eau chaude sanitaire (kg) |
| `conso_kwh` | float | Énergie produite = conso_kg × PCI × rendement (kWh) |
| `nb_cycle` | int | Nombre de cycles brûleur du jour |
| `cumul_kg` | float | Total pellet consommé depuis le début (kg) — base veille + jour en cours |
| `cumul_kwh` | float | Total énergie produite depuis le début (kWh) |
| `cumul_cycle` | int | Total cycles brûleur depuis le début |
| `cumul_cout` | float | Coût cumulé total depuis le début (€) — `SUM(conso_kg × prix_kg)` tous jours passés + contribution live du jour |
| `prix_kg` | float | Prix au kg du lot en cours, logique FIFO (€/kg) |
| `prix_kwh` | float | Prix au kWh = prix_kg / (PCI × rendement) (€/kWh) |
| `tc_ext_max` | float\|null | T° extérieure max du jour (°C) — fallback sur dernier jour connu avant import |
| `tc_ext_min` | float\|null | T° extérieure min du jour (°C) — fallback sur dernier jour connu avant import |
| `silo.remains_kg` | float | Stock pellet restant estimé (kg) |
| `silo.capacity_kg` | float | Capacité totale du silo (kg) |
| `silo.percent` | int | Niveau silo en % |
| `silo.last_fill_date` | string | Date du dernier chargement |
| `ashtray.remains_kg` | float | Capacité cendrier restante avant vidange (kg) |
| `ashtray.capacity_kg` | float | Capacité cendrier (kg) |
| `ashtray.percent` | int | Taux de remplissage du cendrier (%) |
| `ashtray.needs_emptying` | bool | `true` si le cendrier doit être vidé |
| `ashtray.last_empty_date` | string | Date du dernier vidage |
| `maintenance.last_sweep` | string\|null | Date du dernier ramonage |
| `maintenance.last_maintenance` | string\|null | Date du dernier entretien annuel |

> **Logique de prix FIFO :** `prix_kg` correspond au lot dont le cumul livré (somme de toutes les livraisons jusqu'à cette date) est ≥ au cumul consommé (`cumul_kg`). Le prix d'un nouveau lot ne s'applique que lorsque le lot précédent est physiquement épuisé.

### `?action=daily&date=YYYY-MM-DD`

Résumé d'un jour précis. Retourne la synthèse archivée depuis `oko_resume_day`.

- `date` = aujourd'hui ou hier → retourne la synthèse d'hier avec fallback si absente (`is_new: false`)
- `date` < hier → données archivées (`is_new: true`) ou `404` si absent

| Champ | Type | Description |
|---|---|---|
| `date` | string | Date `YYYY-MM-DD` |
| `dju` | float\|null | Degrés-Jours Unifiés |
| `conso_kg` | float\|null | Consommation pellet du jour (kg) |
| `conso_ecs_kg` | float\|null | Dont eau chaude sanitaire (kg) |
| `conso_kwh` | float\|null | Énergie produite (kWh) |
| `nb_cycle` | int\|null | Nombre de cycles brûleur |
| `cumul_kg` | float\|null | Cumul pellet depuis le début (kg) |
| `cumul_kwh` | float\|null | Cumul énergie depuis le début (kWh) |
| `cumul_cycle` | int\|null | Cumul cycles depuis le début |
| `cumul_cout` | float\|null | Coût cumulé depuis le début (€) |
| `prix_kg` | float\|null | Prix au kg FIFO (€/kg) |
| `prix_kwh` | float\|null | Prix au kWh (€/kWh) |
| `tc_ext_max` | float\|null | T° extérieure max (°C) |
| `tc_ext_min` | float\|null | T° extérieure min (°C) |
| `is_new` | bool | `true` si synthèse présente, `false` si fallback (données d'avant-hier) |

### `?action=monthly&month=MM&year=YYYY`

Tableau journalier complet d'un mois depuis `oko_resume_day` + totaux mensuels. Si `month`/`year` sont absents, utilise le mois/année courant.

**Structure de la réponse :**

```json
{
  "month": 3,
  "year": 2026,
  "totals": {
    "dju": float,
    "conso_kg": float,
    "conso_ecs_kg": float,
    "conso_kwh": float,
    "nb_cycle": int,
    "tc_ext_max": float|null,
    "tc_ext_min": float|null
  },
  "days": [ { /* voir tableau ci-dessous */ } ]
}
```

**Champs de chaque jour dans `days[]` :**

| Champ | Type | Description |
|---|---|---|
| `date` | string | Date `YYYY-MM-DD` |
| `dju` | float\|null | Degrés-Jours Unifiés |
| `conso_kg` | float\|null | Consommation pellet du jour (kg) |
| `conso_ecs_kg` | float\|null | Dont eau chaude sanitaire (kg) |
| `conso_kwh` | float\|null | Énergie produite (kWh) |
| `nb_cycle` | int\|null | Nombre de cycles brûleur |
| `cumul_kg` | float\|null | Cumul pellet depuis le début (kg) |
| `cumul_kwh` | float\|null | Cumul énergie depuis le début (kWh) |
| `cumul_cycle` | int\|null | Cumul cycles depuis le début |
| `prix_kg` | float\|null | Prix au kg FIFO (€/kg) |
| `prix_kwh` | float\|null | Prix au kWh (€/kWh) |
| `tc_ext_max` | float\|null | T° extérieure max (°C) |
| `tc_ext_min` | float\|null | T° extérieure min (°C) |
| `silo_pellets_restants` | float\|null | Stock pellet restant estimé au soir de ce jour (kg) |
| `silo_niveau` | int\|null | Niveau silo en % au soir de ce jour |
| `cendrier_capacite_restante` | float\|null | Capacité cendrier restante avant vidange (kg) |
| `cendrier_niveau_de_remplissage` | int\|null | Taux de remplissage du cendrier en % |

> Les valeurs silo et cendrier sont calculées à la volée : stock à la livraison − consommation cumulée depuis cette livraison jusqu'au jour J. `null` si aucun événement de livraison/vidage antérieur n'est enregistré.

**Totaux mensuels (`totals`) :** sommes de `dju`, `conso_kg`, `conso_ecs_kg`, `conso_kwh`, `nb_cycle` ; max de `tc_ext_max` ; min de `tc_ext_min` sur le mois.

### `?action=status`

Niveau silo + cendrier + maintenance uniquement (sans données de consommation).

```json
{
  "silo": { "remains_kg", "capacity_kg", "percent", "last_fill_date" },
  "ashtray": { "remains_kg", "capacity_kg", "percent", "needs_emptying", "last_empty_date" },
  "maintenance": { "last_sweep", "last_maintenance" }
}
```

> En cas de configuration manquante, `silo` ou `ashtray` retournent `{"error": "no_silo_size"}` / `{"error": "no_ashtray_info"}` etc.
