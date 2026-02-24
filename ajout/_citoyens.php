<?php

$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_carte.php?id=xx";
    exit;
}

// ================= CONFIG BDD =================
$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/*
|--------------------------------------------------------------------------
| 1. Vérifie si zone_perso existe
|--------------------------------------------------------------------------
*/
$exists = $pdo->query("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE()
      AND table_name = 'zone_perso'
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| 2. Création si inexistante
|--------------------------------------------------------------------------
*/
if (!$exists) {

    $pdo->exec("
        CREATE TABLE zone_perso (
            id INT NOT NULL,
            town_id INT NOT NULL,
            day_update INT NOT NULL DEFAULT 0,
            regen TINYINT(1) NOT NULL DEFAULT 1,
            ruin_regen TINYINT(1) NOT NULL DEFAULT 1,
            position TINYINT NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");

    $pdo->exec("
        INSERT INTO zone_perso (id, town_id, day_update, regen, ruin_regen, position)
        SELECT
            z.id,
            z.town_id,
            0,
            1,
            1,
            CASE
                WHEN (ABS(z.x) + ABS(z.y) <= 3 AND ABS(z.x) < 3 AND ABS(z.y) < 3) THEN 5
                WHEN (z.y >= 0 AND ABS(z.x) <= FLOOR(z.y / 2)) THEN 2
                WHEN (z.y <= 0 AND ABS(z.x) <= FLOOR(-z.y / 2)) THEN 8
                WHEN (z.x <= 0 AND ABS(z.y) <= FLOOR(-z.x / 2)) THEN 4
                WHEN (z.x >= 0 AND ABS(z.y) <= FLOOR(z.x / 2)) THEN 6
                WHEN (z.x > 0 AND z.y > 0) THEN 3
                WHEN (z.x < 0 AND z.y > 0) THEN 1
                WHEN (z.x > 0 AND z.y < 0) THEN 9
                WHEN (z.x < 0 AND z.y < 0) THEN 7
            END
        FROM zone z
    ");

} else {
    if (!True){
        /*
        |--------------------------------------------------------------------------
        | 3. Suppression des zones orphelines
        |--------------------------------------------------------------------------
        */
        $pdo->exec("
            DELETE zp
            FROM zone_perso zp
            LEFT JOIN zone z ON z.id = zp.id
            WHERE z.id IS NULL
        ");

        /*
        |--------------------------------------------------------------------------
        | 4. Ajout des nouvelles zones
        |--------------------------------------------------------------------------
        */
        $pdo->exec("
            INSERT INTO zone_perso (id, town_id, day_update, regen, ruin_regen, position)
            SELECT
                z.id,
                z.town_id,
                0,
                1,
                1,
                CASE
                    WHEN (ABS(z.x) + ABS(z.y) <= 3 AND ABS(z.x) < 3 AND ABS(z.y) < 3) THEN 5
                    WHEN (z.y >= 0 AND ABS(z.x) <= FLOOR(z.y / 2)) THEN 2
                    WHEN (z.y <= 0 AND ABS(z.x) <= FLOOR(-z.y / 2)) THEN 8
                    WHEN (z.x <= 0 AND ABS(z.y) <= FLOOR(-z.x / 2)) THEN 4
                    WHEN (z.x >= 0 AND ABS(z.y) <= FLOOR(z.x / 2)) THEN 6
                    WHEN (z.x > 0 AND z.y > 0) THEN 3
                    WHEN (z.x < 0 AND z.y > 0) THEN 1
                    WHEN (z.x > 0 AND z.y < 0) THEN 9
                    WHEN (z.x < 0 AND z.y < 0) THEN 7
                END
            FROM zone z
            LEFT JOIN zone_perso zp ON zp.id = z.id
            WHERE zp.id IS NULL
        ");
    }
}


/**
 * 1. Récupération du jour actuel de la ville
 */

$stmt = $pdo->prepare("SELECT day FROM town WHERE id = ?");
$stmt->execute([$town_id]);
$currentDay = (int)$stmt->fetchColumn();

/**
 * 2. Récupération des zones visitées aujourd’hui (discovery_status = 2)
 */
$stmt = $pdo->prepare("
    SELECT id, digs, ruin_digs
    FROM zone
    WHERE town_id = ?
    AND discovery_status = 2
");
$stmt->execute([$town_id]);
$zones = $stmt->fetchAll();

/**
 * 3. Préparation et exécution de la requête UPDATE
 */
$update = $pdo->prepare("
    UPDATE zone_perso
    SET
        day_update = :day_update,
        regen = CASE
            WHEN :digs = 0 THEN 0
            ELSE regen
        END,
        ruin_regen = CASE
            WHEN :ruin_digs = 0 THEN 0
            ELSE 1
        END
    WHERE id = :zone_id
      AND town_id = :town_id
");


foreach ($zones as $zone) {
    $update->execute([
        ':day_update' => $currentDay,
        ':digs'       => $zone['digs'],
        ':ruin_digs'  => $zone['ruin_digs'],
        ':zone_id'    => $zone['id'],
        ':town_id'    => $town_id,
    ]);
}

// ================= IMAGES DES OBJETS =================
$itemImages = [];
foreach (glob(__DIR__ . "/build/images/item/item_*.gif") as $file) {
    if (preg_match('/item_(.+?)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $itemImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

// Garder uniquement le nom logique (sans _#xx)
function getItemImagePathFast($name, $map) {
    $key = preg_replace('/_#\d+$/', '', $name);
    return $map[$key] ?? null;
}

// ================= ZONES =================
$sqlZones = $pdo->prepare('
    SELECT id, floor_id, x, y, discovery_status, prototype_id
    FROM zone
    WHERE town_id = ?
');
$sqlZones->execute([$town_id]);
$zones = $sqlZones->fetchAll(PDO::FETCH_ASSOC);

// ================= REGEN PAR ZONE =================
$sqlRegen = $pdo->prepare('
    SELECT id, regen, ruin_regen, day_update
    FROM zone_perso
    WHERE town_id = ?
');
$sqlRegen->execute([$town_id]);

$zonePerso = [];
while ($r = $sqlRegen->fetch(PDO::FETCH_ASSOC)) {
    $zonePerso[$r['id']] = $r;
}

// ================= Prototype des bâtiments =================
$zoneProtoStmt = $pdo->query('SELECT id, label FROM zone_prototype');
$zoneProtoMap = $zoneProtoStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ================= Citoyens par zone =================
$sqlCitizens = $pdo->prepare('
    SELECT c.zone_id, GROUP_CONCAT(u.name) AS names
    FROM citizen c
    JOIN user u ON u.id = c.user_id
    WHERE c.town_id = ?
    GROUP BY c.zone_id
');
$sqlCitizens->execute([$town_id]);

$citizensByZone = [];
while ($c = $sqlCitizens->fetch(PDO::FETCH_ASSOC)) {
    $citizensByZone[$c['zone_id']] = explode(',', $c['names']);
}

// Category objets
$categoryLabels = [
    1 => 'Ress',
    2 => 'Déco',
    3 => 'Arme',
    4 => 'Cont',
    5 => 'Défs',
    6 => 'Drog',
    7 => 'Food',
    8 => 'Autr',
];

// ================= OBJETS PAR CASE =================

// prototypes objets
$protoMap = [];
$protoStmt = $pdo->query('SELECT id, name, category_id, heavy FROM item_prototype order by id ASC');
while ($row = $protoStmt->fetch(PDO::FETCH_ASSOC)) {$protoMap[$row['id']] = $row;}

$map = [];
$xs = [];
$ys = [];

// Récupérer tous les items d’un coup
// Filtrer les zones où discovery_status > 0
$discoveredZones = array_filter($zones, function($zone) {
    return $zone['discovery_status'] > 0;
});

// Récupérer les floor_id de ces zones découvertes
$zoneIds = array_column($discoveredZones, 'floor_id');

if (!empty($zoneIds)) {
    $in  = str_repeat('?,', count($zoneIds) - 1) . '?';
    $sqlAllItems = $pdo->prepare("
        SELECT inventory_id, prototype_id, broken
        FROM item
        WHERE inventory_id IN ($in)
        AND prototype_id not in (271, 272, 273, 274)
        ORDER BY prototype_id ASC
    ");
    $sqlAllItems->execute($zoneIds);
    $itemsRaw = $sqlAllItems->fetchAll(PDO::FETCH_GROUP);
} else {
    // Aucun item si aucune zone découverte
    $itemsRaw = [];
}



// ================= RÉCAP GLOBAL DES OBJETS (PAR CATÉGORIE / OBJET) =================

$itemSummary = [];

// Initialiser les catégories
foreach ($categoryLabels as $catId => $label) {
    $itemSummary[$catId] = [];
}

// Parcourir tous les objets de toutes les zones
foreach ($itemsRaw as $inventoryId => $items) {
    foreach ($items as $item) {

        $protoId = (int)$item['prototype_id'];
        $broken  = (int)$item['broken'];

        if (!isset($protoMap[$protoId])) {
            continue;
        }

        $proto = $protoMap[$protoId];
        $catId = (int)$proto['category_id'];
        $name  = $proto['name'];

        // Clé unique par objet + état
        $key = $protoId . '_' . $broken;

        if (!isset($itemSummary[$catId][$key])) {
            $itemSummary[$catId][$key] = [
                'prototype_id' => $protoId, // <-- OBLIGATOIRE
                'name'   => $name,
                'broken' => $broken,
                'count'  => 0
            ];
        }

        $itemSummary[$catId][$key]['count']++;
    }
}

// Mouse-over des bâtiments dans le récap
$dropStmt = $pdo->query("SELECT
    zp.id AS zone_proto_id,
    ip.icon AS resource_name,
    ROUND(
        ige.chance / SUM(ige.chance) OVER (PARTITION BY zp.id) * 100,
        2
    ) AS percentage
    FROM zone_prototype zp
    LEFT JOIN item_group ig ON zp.drops_id = ig.id
    LEFT JOIN item_group_entry ige ON ige.item_group_id = ig.id
    LEFT JOIN item_prototype ip ON ige.prototype_id = ip.id
    ORDER BY zp.id, percentage DESC");
$dropsByProto = [];

while ($row = $dropStmt->fetch(PDO::FETCH_ASSOC)) {
    if (!$row['resource_name']) continue;

    $dropsByProto[$row['zone_proto_id']][] = [
        'name' => $row['resource_name'],
        'pct'  => $row['percentage']
    ];
}


// ================= Récapitulatif bâtiments (droite) =================

$sqlRecap = $pdo->prepare('
    SELECT
        z.x,
        z.y,
        z.explorable_floors,
        z.bury_count,
        z.prototype_id,
        zp.ruin_regen
    FROM zone z
    LEFT JOIN zone_perso zp ON zp.id = z.id
    WHERE z.town_id = ?
      AND z.discovery_status > 0
      AND z.prototype_id IS NOT NULL
');
$sqlRecap->execute([$town_id]);
$zonesRecap = $sqlRecap->fetchAll(PDO::FETCH_ASSOC);


$buildingsRecap = ['near'=>[], 'far'=>[], 'multi'=>[]];

foreach ($zonesRecap as $z) {
    $distance = (int) round(sqrt($z['x']*$z['x'] + $z['y']*$z['y']));

    $drops = [];
    foreach ($dropsByProto[$z['prototype_id']] ?? [] as $d) {
        $imgPath = getItemImagePathFast($d['name'], $itemImages);
        if ($imgPath) {
            $drops[] = [
                'img' => $imgPath,
                'pct' => $d['pct']
            ];
        }
    }

    $b = [
        'name' => tr($zoneProtoMap[$z['prototype_id']]),
        'x' => $z['x'],
        'y' => $z['y'],
        'distance' => $distance,
        'full' => ($z['ruin_regen'] > 0),
        'bury' => (int)$z['bury_count'],
        'drops' => $drops
    ];

    if ($z['explorable_floors'] > 1) {
        $buildingsRecap['multi'][] = $b;
    } elseif ($distance < 10) {
        $buildingsRecap['near'][] = $b;
    } else {
        $buildingsRecap['far'][] = $b;
    }
}

// ================= Construire la carte (gauche) =================
$map = [];
$xs = [];
$ys = [];

foreach ($zones as $z) {
    $zoneId  = (int)$z['id'];
    $floorId = (int)$z['floor_id'];
    $x       = (int)$z['x'];
    $y       = (int)$z['y'];

    $perso = $zonePerso[$zoneId] ?? [];

    $regen = (int)($perso['regen'] ?? 0);

    $cell = [
        'zone_id'      => $zoneId,
        'x'            => $x,
        'y'            => $y,
        'discovery'    => (int)$z['discovery_status'],
        'items'        => [],
        'regen'        => $regen,
        'prototype_id' => $z['prototype_id'],
        'ruin_regen'   => (int)($perso['ruin_regen'] ?? 0),
        'day_update'   => $perso['day_update'] ?? null,
        'citizens'     => $citizensByZone[$zoneId] ?? [],
    ];

    if ($cell['discovery'] !== 0 && isset($itemsRaw[$floorId])) {
        foreach ($itemsRaw[$floorId] as $row) {
            $pid    = (int)$row['prototype_id'];
            $broken = (int)$row['broken'];

            if (!isset($protoMap[$pid])) continue;

            $proto = $protoMap[$pid];
            $cat   = (int)$proto['category_id'];
            $name  = $proto['name'];

            if (!isset($cell['items'][$cat][$name])) {
                $cell['items'][$cat][$name] = [
                    'ok' => 0,
                    'broken' => 0,
                    'heavy' => !empty($proto['heavy']) ? 1 : 0,  // <-- ajouté
                ];
            }


            if ($broken) {
                $cell['items'][$cat][$name]['broken']++;
            } else {
                $cell['items'][$cat][$name]['ok']++;
            }
        }
    }

    $map[$y][$x] = $cell;
    $xs[] = $x;
    $ys[] = $y;
}

$minX = min($xs);
$maxX = max($xs);
$minY = min($ys);
$maxY = max($ys);

// Bouton pour mettre à jour la regen
if (isset($_GET['maj_regen'])) {

    // 2️⃣ Récupérer la direction du vent pour ce jour
    $stmtWind = $pdo->prepare("SELECT wind_direction FROM gazette WHERE town_id = ? AND day = ?");
    $stmtWind->execute([$town_id, $currentDay]);
    $windDirection = (int)$stmtWind->fetchColumn();

    // 3️⃣ Mettre à jour zone_perso : +1 à regen si position = wind_direction
    $stmtUpdate = $pdo->prepare("
        UPDATE zone_perso
        SET regen = regen + 1, day_update = :day_update
        WHERE town_id = :town_id
          AND position = :wind_direction
    ");
    $stmtUpdate->execute([
        ':day_update'    => $currentDay,
        ':town_id'       => $town_id,
        ':wind_direction'=> $windDirection
    ]);

    // Rediriger pour éviter le re-post
    header("Location: ?id={$town_id}");
    exit;
}

// Traduction des bâtiments
function tr(string $key): string {
    static $tr = null;

    if ($tr === null) {
        $tr = require __DIR__ . '/translations_game_fr.php';
    }

    return $tr[$key] ?? $key;
}

// Noter tous les x/y qui ont des citoyens 
$stmt = $pdo->prepare("SELECT z.x, z.y FROM citizen c JOIN zone z ON z.id = c.zone_id WHERE c.town_id = ?");
$stmt->execute([$town_id]);
$citizens = array_map(function($r){ return ['x'=>(int)$r['x'], 'y'=>(int)$r['y']]; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Tableau des expéditions 
$expeditions = [];
$stmt = $pdo->prepare("
    SELECT id, length, data, label
    FROM expedition_route
    WHERE owner_id IN (
        SELECT id FROM citizen WHERE town_id = ?
    )
    ORDER BY label ASC
");
$stmt->execute([$town_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $expeditions[] = [
        'id'     => (int)$row['id'],
        'label'  => $row['label'],
        'length' => (int)$row['length'],
        'path'   => json_decode($row['data'], true)
    ];
}

// -------------------------------------------------- COLONNE DU RECAP DES VENTS 
$winds = [];

$stmt = $pdo->prepare("
    SELECT day, wind_direction 
    FROM gazette 
    WHERE town_id = ?
    AND wind_direction != 0
    ORDER BY day ASC
");
$stmt->execute([$town_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $winds[(int)$row['day']] = (int)$row['wind_direction'];
}

/* Mapping direction -> flèche */
$windArrows = [
    1 => 'N-O',
    2 => ' N ',
    3 => 'N-E',
    4 => ' O ',
    5 => ' / ',
    6 => ' E ',
    7 => 'S-O',
    8 => ' S ',
    9 => 'S-E'
];



// =====================================================================================================================================================================================
// HTML
// =====================================================================================================================================================================================

?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Carte : <?= htmlspecialchars($town_id) ?></title>

<style>
body {background:#FFF;font-family:Arial;}
/* GLOBAL GRID */
.map-container {display:grid;grid-template-columns: 30px auto 30px;grid-template-rows: auto auto auto;width: fit-content;}
/* MAP */
.map {display:grid;grid-auto-rows:30px;}
.cell {width:30px;height:30px;border:0.5px solid #333;background:#2e2e2e;position:relative;}
.city { background:#000; }
.cell:hover {outline:1px solid #777;z-index:5;}
/* TOOLTIP */
.tooltip {display:none;position:absolute;top:32px;left:0;background:#fff;border:1px solid #555;padding:5px;font-size:12px;white-space:nowrap;z-index: 1000;}
.cell:hover .tooltip { display:block; }
/* ITEMS */
.item-count {position:absolute;bottom:1px;right:3px;font-size:11px;font-weight:bold;pointer-events:none;}
/* REGEN */
.cell.regen-0 { background:#ff9900; }
.cell.regen-1 { background:#b6d7a8; }
.cell.regen-2 { background:#a4c2f4; }
.cell.regen-3 { background:#d5a6bd; }
.cell.regen-4 { background:#ad7b71; }
/* COORDS */
.coords-x,.coords-y {background:#000;}
.coords-x { display:grid; }
.coords-y { display:flex; flex-direction:column; }
.coord {width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:bold;color:#fff;background:#000;border:0.5px solid #222;box-sizing: border-box;}
.layout {display: flex;align-items: flex-start;gap: 10px;}
/* ======================================== RECAP BATIMENTS ======================================== */
.sidebar {width: 260px;min-width: 260px;display: grid;grid-template-rows: auto auto;gap: 10px;}
.map-wrapper {flex: 1;}
.recap-grid {display: grid;grid-template-columns: repeat(3, 1fr);gap: 6px;width: 100%;}
.recap-cell {border: 1px solid #333;padding: 3px;border-radius: 4px;background: #f9f9f9;width: 100%;box-sizing: border-box;}
.building-line {display: grid;grid-template-columns: 1fr 50px 40px 50px 30px;gap: 6px;align-items: center;margin-bottom: 2px;font-size: 12px;width: 100%;box-sizing: border-box;white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
.building-line {display: grid;grid-template-columns: 1fr 50px 40px 50px 30px;gap: 12px;align-items: center;margin-bottom: 2px;font-size: 12px;width: 100%;box-sizing: border-box;white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
.building-line > div {display: flex;align-items: center;justify-content: flex-start;}
.recap-cell b {display: block;text-align: center}
.b-name, .b-coords, .b-distance, .b-full, .b-bury {text-align: left;}
.b-full.empty {color: red;font-weight: bold;}
.b-full.full {color: inherit;font-weight: normal;}
/* ======================================== RECAP OBJETS ======================================== */
.item-summary-full {width: 100%;}
.item-summary-full .recap-cell {width: 100%;box-sizing: border-box;}
.recap-objets-category-title {font-weight: bold;font-size: 14px;margin-bottom: 4px;}
.recap-objets-list {display: flex;flex-wrap: wrap;gap: 4px;}
.recap-objets-item {display: flex;flex-direction: row;align-items: center;font-size: 10px;text-align: center;background: #f5f5f5;border: 2px solid #ccc;border-radius: 4px;padding: 2px 4px;gap: 2px;cursor: pointer;}
.recap-objets-item img {width: 18px;height: 18px;object-fit: contain;}
/* Compteur à gauche de l’image */
.recap-objets-count {font-weight: bold;font-size: 10px;}
/* Objet cassé / broken */
.recap-objets-item.broken {opacity: 0.6;text-decoration: line-through;}
.recap-objets-category-title{padding-top:2px;}
/* ======================================== DISTANCE PA ======================================== */
.pa-border-top { position:absolute; top:0; left:0; right:0; height:2px; background:red; pointer-events:none; z-index:10; }
.pa-border-bottom { position:absolute; bottom:0; left:0; right:0; height:2px; background:red; pointer-events:none; z-index:10; }
.pa-border-left { position:absolute; top:0; bottom:0; left:0; width:2px; background:red; pointer-events:none; z-index:10; }
.pa-border-right { position:absolute; top:0; bottom:0; right:0; width:2px; background:red; pointer-events:none; z-index:10; }
/* ======================================== DISTANCE KM ======================================== */
.km-border-top { position:absolute; top:0; left:0; right:0; height:3px; background:cyan; pointer-events:none; z-index:10; }
.km-border-bottom { position:absolute; bottom:0; left:0; right:0; height:3px; background:cyan; pointer-events:none; z-index:10; }
.km-border-left { position:absolute; top:0; bottom:0; left:0; width:3px; background:cyan; pointer-events:none; z-index:10; }
.km-border-right { position:absolute; top:0; bottom:0; right:0; width:3px; background:cyan; pointer-events:none; z-index:10; }
/* ======================================== Barres entre les distances ======================================== */
.item {display: flex;align-items: center;gap: 5px;padding: 0 15px;}
.item:not(:first-child) {border-left: 1px solid #ccc;}
/* ======================================== DISTANCE SCRUT ======================================== */
.scrut-border-top { position:absolute; top:0; left:0; right:0; height:1px; background:blue; pointer-events:none; z-index:10; }
.scrut-border-bottom { position:absolute; bottom:0; left:0; right:0; height:1px; background:blue; pointer-events:none; z-index:10; }
.scrut-border-left { position:absolute; top:0; bottom:0; left:0; width:1px; background:blue; pointer-events:none; z-index:10; }
.scrut-border-right { position:absolute; top:0; bottom:0; right:0; width:1px; background:blue; pointer-events:none; z-index:10; }
/* ======================================== DISTANCE BATIMENTS ======================================== */
.building-border { position:absolute; top:0; left:0; right:0; bottom:0; border:2px solid black; pointer-events:none; z-index:15; box-sizing:border-box; }
/* ======================================== DISTANCE CITOYENS ======================================== */
.citizen-border { position:absolute; top:0; left:0; right:0; bottom:0; border:2px solid yellow; pointer-events:none; z-index:9; box-sizing:border-box; }
/* ======================================== DISTANCE OBJETS ======================================== */
.item-border { position:absolute; top:0; left:0; right:0; bottom:0; border:3px solid limegreen; pointer-events:none; z-index:17; box-sizing:border-box; }
.recap-objets-item.active-object { border: 3px solid limegreen; }
/* ======================================== DISTANCE ZOO ======================================== */
.zoo-rectangle {position: absolute;border: 3px solid black;pointer-events: none;z-index: 50;}
/* ======================================== MOUSE-OVER RECAP DES BATIMENTS ======================================== */
#building-tooltip {position: absolute;display: none;background: #fff;color: #000;border: 1px solid #555;border-radius: 4px;padding: 6px 8px;font-size: 11px;z-index: 2000;pointer-events: none;white-space: nowrap;box-shadow: 0 2px 6px rgba(0,0,0,0.25);}
.building-tooltip-line {display: flex;align-items: center;gap: 4px;margin-bottom: 2px;}
.building-tooltip-line img {width: 16px;height: 16px;object-fit: contain;}
/* ======================================== ENCAPSULEMENT DE LA MAP ET DES FILTRES ======================================== */
.map-wrapper {display: flex;flex-direction: column;align-items: flex-start;width: fit-content;flex: 0 0 auto;}
.map-filters {font-size: 14px;}
.filters-line {display: flex;align-items: center;}
/* ======================================== RECAP EXPEDITIONS ======================================== */
.recap-expeditions {width: 100%;}
.recap-expeditions .recap-cell {width: 100%;box-sizing: border-box;}
.expeditions-grid {display: grid;grid-template-columns: repeat(4, 1fr);gap: 4px 6px;font-size: 11px;}
.expedition-line {white-space: nowrap;}
/* ======================================== EXPEDITIONS PATH ======================================== */
.cell {position: relative;}
.cell .exp-line {position: absolute;background: #000;pointer-events: none;z-index: 18;}
.exp-h {height: 3px;top: 50%;transform: translateY(-50%);}
.exp-v {width: 3px;left: 50%;transform: translateX(-50%);}
/* ======================================== VENT COLUMN ======================================== */
.wind-wrapper {width: 70px;min-width: unset;background: #f4f4f4;border: 1px solid #bbb;border-radius: 6px;padding: 6px;font-size: 12px;box-shadow: 0 2px 4px rgba(0,0,0,0.08);}
.wind-title {text-align: center;font-weight: bold;margin-bottom: 6px;padding-bottom: 4px;border-bottom: 1px solid #ccc;}
.wind-row {display: flex;justify-content: space-between;align-items: center;padding: 2px 0;}
.wind-day {font-weight: bold;}
.wind-arrow {font-size: 16px;}
/* ======================================== WIND TABLE ======================================== */

.wind-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.wind-table th {
    background: #e0e0e0;
    border-bottom: 1px solid #bbb;
    padding: 4px;
    text-align: center;
}

.wind-table td {
    padding: 1px;
    text-align: center;
    border-bottom: 1px solid #eee;
}

.wind-table tr:nth-child(even) {
    background: #fafafa;
}

.wind-table tr:hover {
    background: #ddd;
}
</style>
</head>

<body style="margin-top:0px;margin-bottom:0px;">

<div style="position: relative; padding: 8px 12px; display: flex; align-items: center; min-height: unset;">

    <!-- PARTIE GAUCHE -->
    <?php
        $leftContent =
            '<a href="?id=' . (int)$town_id . '&maj_regen=1"
                style="padding:2px 10px; background:#4CAF50; color:#fff; text-decoration:none; border-radius:4px;">
                MAJ Regen
            </a>';    ?>
    <?php if (!empty($leftContent)) { ?>
        <div style="padding: 6px 12px;background: rgba(245,245,245,0.9);border-radius: 14px;border: 1px solid #b5b5b5;font-size: 13px;color: #333;box-shadow: 0 2px 5px rgba(0,0,0,0.08);margin-right: auto;white-space: nowrap;"><?= $leftContent ?></div>
    <?php } ?>

    <!-- MENU CENTRAL -->
    <div style="position: absolute;left: 50%;transform: translateX(-50%);display: flex;gap: 8px;padding: 6px 12px;background: rgb(172,106,57);border-radius: 18px;border: 1px solid #a9a9a9;box-shadow: 0 3px 8px rgba(0,0,0,0.1);white-space: nowrap;">
        <?php
            $pages = ["_citoyens.php" => "Citoyens","_carte.php" => "Carte","_banque.php" => "Banque","_chantiers.php" => "Chantiers","_plans.php" => "Plans", "_objets.php" => "OD", "_veille.php" => "Veille", "_ruine.php" => "Ruine", ];
            foreach ($pages as $file => $label) { echo '<a href="http://192.168.0.246:8081/' . $file . '?id=' . $town_id . '"style="text-decoration: none; padding: 3px 8px; border-radius: 8px; font-size: 12px; color: #333; background: #f2f2f2; border: 1px solid #c5c5c5;"onmouseover="this.style.background=\'#e4e4e4\';"onmouseout="this.style.background=\'#f2f2f2\';"><strong>' . $label . '</strong></a>';}
        ?>
    </div>

    <!-- PARTIE DROITE -->
    <?php
        $rightContent = '<strong>Affichage :</strong><label><input type="checkbox" id="filter-heavy">Encombrants</label><label><input type="checkbox" id="filter-light" checked>Légers</label>';
    ?>
    <?php if (!empty($rightContent ?? '')) { ?>
        <div style="padding: 6px 12px;background: rgba(245,245,245,0.9);border-radius: 14px;border: 1px solid #b5b5b5;font-size: 13px;color: #333;box-shadow: 0 2px 5px rgba(0,0,0,0.08);margin-left: auto;white-space: nowrap;">
            <?= $rightContent ?>
        </div>
    <?php } ?>

</div>

<div class="layout">
    <!-- WIND COLUMN -->
    <div class="wind-wrapper">
        <div class="wind-title"></div>

        <table class="wind-table">
            <thead>
                <tr>
                    <th>J</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($winds as $day => $direction): ?>
                    <tr>
                        <td><strong><?= $day ?></strong></td>
                        <td><?= $windArrows[$direction] ?? '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="map-wrapper">
        <div class="map-container">

            <!-- Coord X Top -->
            <div></div>
            <div class="coords-x" style="grid-template-columns:repeat(<?= $maxX-$minX+1 ?>,30px)">
                <?php for($x=$minX;$x<=$maxX;$x++): ?>
                    <div class="coord"><?= $x ?></div>
                <?php endfor; ?>
            </div>
            <div></div>

            <!-- Coord Y Left + Map + Coord Y Right -->
            <div class="coords-y">
                <?php for($y=$maxY;$y>=$minY;$y--): ?>
                    <div class="coord"><?= $y ?></div>
                <?php endfor; ?>
            </div>

            <div class="map" style="grid-template-columns:repeat(<?= $maxX-$minX+1 ?>,30px)">
            <?php for($y=$maxY;$y>=$minY;$y--): ?>
                <?php for($x=$minX;$x<=$maxX;$x++):
                    $cell = $map[$y][$x] ?? null;
                    $isCity = ($x === 0 && $y === 0);

                    $regenClass = '';
                    if (!$isCity && $cell && $cell['discovery'] !== 0) {
                        $r = $cell['regen'];
                        $regenClass = 'regen-' . ($r >= 4 ? 4 : $r);
                    }

                    $dataAttrs = '';
                    if ($cell) {
                        $dataAttrs = 'data-x="'. $x .'" '
                            . 'data-y="'. $y .'" '
                            . 'data-items="'. htmlspecialchars(json_encode(
                                array_map(function($itemsByName) use ($itemImages, $protoMap) {
                                    $out = [];
                                    foreach ($itemsByName as $name => $counts) {
                                        $path = getItemImagePathFast($name, $itemImages);

                                        $protoId = $counts['prototype_id'] ?? null;
                                        $isHeavy = ($protoId && !empty($protoMap[$protoId]['heavy'])) ? 1 : 0;

                                        $out[$path ?: $name] = [
                                            'ok'     => (int)($counts['ok'] ?? 0),
                                            'broken' => (int)($counts['broken'] ?? 0),
                                            'heavy'  => $isHeavy
                                        ];
                                    }
                                    return $out;
                                }, $cell['items'])
                            )) .'" '
                            . 'data-citizens="'. htmlspecialchars(json_encode($cell['citizens'])) .'" '
                            . 'data-prototype="'. htmlspecialchars(
                                (!empty($cell['prototype_id']) && isset($zoneProtoMap[$cell['prototype_id']]))
                                    ? tr($zoneProtoMap[$cell['prototype_id']])
                                    : ''
                            ) .'" '
                            . 'data-day_update="'. htmlspecialchars($cell['day_update'] ?? '') .'" '
                            . 'data-ruin_regen="'. htmlspecialchars($cell['ruin_regen'] ?? '') .'" '
                            . 'data-discovery="'. ($cell['discovery'] ?? 0) .'"';
                    }

                $heavyTotal = 0;
                $lightTotal = 0;

                if ($cell && $cell['discovery'] !== 0) {
                    foreach ($cell['items'] as $itemsByCat) {
                        foreach ($itemsByCat as $item) {
                            $qty = ($item['ok'] ?? 0) + ($item['broken'] ?? 0);
                            if (!empty($item['heavy'])) {
                                $heavyTotal += $qty;
                            } else {
                                $lightTotal += $qty;
                            }
                        }
                    }
                }
                ?>
                <div class="cell <?= $isCity ? 'city' : '' ?> <?= $regenClass ?>"data-heavy="<?= $heavyTotal ?>"data-light="<?= $lightTotal ?>"<?= $dataAttrs ?>>
                    <?php if ($cell && $cell['discovery'] !== 0): ?>
                    <div class="item-count"
                        data-heavy="<?= $heavyTotal ?>"
                        data-light="<?= $lightTotal ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
            <?php endfor; ?>
            </div>

            <div class="coords-y">
                <?php for($y=$maxY;$y>=$minY;$y--): ?>
                    <div class="coord"><?= $y ?></div>
                <?php endfor; ?>
            </div>

            <!-- Coord X Bottom -->
            <div></div>
            <div class="coords-x" style="grid-template-columns:repeat(<?= $maxX-$minX+1 ?>,30px)">
                <?php for($x=$minX;$x<=$maxX;$x++): ?>
                    <div class="coord"><?= $x ?></div>
                <?php endfor; ?>
            </div>
            <div></div>

        </div> <!-- class="map-container" -->


        <div class="map-filters">
            <!-- CHECKBOX PA / KM / SCRUT / ...  -->

            <div>
                <span style="color:red;padding-left: 15px;">PA : </span>
                <label><input type="checkbox" class="pa-toggle" data-pa="10" > 10</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="12" > 12</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="14" > 14</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="16" > 16</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="22" > 22</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="24" > 24</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="26" > 26</label>
                <label><input type="checkbox" class="pa-toggle" data-pa="32" > 32</label>
            </div>
            <div>
                <span style="color:rgb(0, 255, 255);padding-left: 15px;">KM :</span>
                <label><input type="checkbox" class="km-toggle" data-km="6" > 6</label>
                <label><input type="checkbox" class="km-toggle" data-km="10"> 10</label>
                <label><input type="checkbox" class="km-toggle" data-km="11"> 11</label>
                <label><input type="checkbox" class="km-toggle" data-km="15" > 15</label>
                <label><input type="checkbox" class="km-toggle" data-km="21"> 21</label>
            </div>
            <div style="display:flex; align-items:center;">
                <div class="item">
                    <span style="color:blue;">Scrut :</span>
                    <label><input type="checkbox" id="scrut-toggle" checked> Oui</label>
                </div>
                <div class="item">
                    <span style="color:black;">Bâtiment :</span>
                    <label><input type="checkbox" id="building-toggle" checked> Oui</label>
                </div>
                <div class="item">
                    <span style="color:rgb(201, 198, 0);">Citoyens :</span>
                    <label><input type="checkbox" id="citizen-toggle" checked> Oui</label>
                </div>
                <div class="item">
                    <span style="color:black;">Zoo :</span>
                    <label><input type="checkbox" id="zoo-toggle" checked> Oui</label>
                </div>
            </div>
        </div> <!--class="map-filters">-->
    </div> <!--class="map-wrapper">-->
    <div id="tooltip" class="tooltip"></div>

<!-- ========================= RECAP DES BATIMENTS ========================== -->
    <div class="sidebar">
        <div class="recap-grid">
            <!-- COLONNE 1 : Plan JAUNE -->
            <div class="recap-cell">
                <b>Plan JAUNE</b>
                <?php foreach ($buildingsRecap['near'] as $b): ?>
                    <div class="building-line">
                        <div class="b-name building-tooltip-target"
                            data-drops='<?= json_encode($b["drops"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <?= $b['name'] ?>
                        </div>
                        <div class="b-coords">(<?= $b['x'] ?>,<?= $b['y'] ?>)</div>
                        <div class="b-distance"><?= $b['distance'] ?> km</div>
                        <div class="b-full <?= $b['full'] ? 'full' : 'empty' ?>"><?= $b['full'] ? 'Plein' : 'Vide' ?></div>
                        <div class="b-bury"><?= $b['bury'] > 0 ? '('.$b['bury'].')' : '' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- COLONNE 2 : Plan BLEU -->
            <div class="recap-cell">
                <b>Plan BLEU</b>
                <?php foreach ($buildingsRecap['far'] as $b): ?>
                    <div class="building-line">
                        <div class="b-name building-tooltip-target"
                            data-drops='<?= json_encode($b["drops"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <?= $b['name'] ?>
                        </div>
                        <div class="b-coords">(<?= $b['x'] ?>,<?= $b['y'] ?>)</div>
                        <div class="b-distance"><?= $b['distance'] ?> km</div>
                        <div class="b-full <?= $b['full'] ? 'full' : 'empty' ?>"><?= $b['full'] ? 'Plein' : 'Vide' ?></div>
                        <div class="b-bury"><?= $b['bury'] > 0 ? '('.$b['bury'].')' : '' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- COLONNE 3 : Ruine -->
            <div class="recap-cell">
                <b>Ruine</b>
                <?php foreach ($buildingsRecap['multi'] as $b): ?>
                    <div class="building-line">
                        <div class="b-name building-tooltip-target"
                            data-drops='<?= json_encode($b["drops"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <?= $b['name'] ?>
                        </div>
                        <div class="b-coords">(<?= $b['x'] ?>,<?= $b['y'] ?>)</div>
                        <div class="b-distance"><?= $b['distance'] ?> km</div>
                        <div class="b-bury"><?= $b['bury'] > 0 ? '('.$b['bury'].')' : '' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="building-tooltip" style="position:absolute; display:none; padding:5px 8px; font-size:11px; border-radius:4px; z-index:2000; pointer-events:none;"></div>


    <!-- ========================= RECAP DES OBJETS  ========================== -->

        <div class="item-summary-full">
            <div class="recap-cell">
                <?php foreach ($categoryLabels as $catId => $catLabel): ?>
                    <?php if (empty($itemSummary[$catId])) continue; ?>

                    <div class="recap-objets-category">
                        <div class="recap-objets-category-title"><?= htmlspecialchars($catLabel) ?></div>

                        <div class="recap-objets-list">
                            <?php 
                            // Regrouper les objets par nom et compter les cassés / pas cassés
                            $groupedItems = [];

                            foreach ($itemSummary[$catId] as $item) {
                                $protoId = $item['prototype_id'];

                                if (!isset($groupedItems[$protoId])) {
                                    $groupedItems[$protoId] = [
                                        'name' => $item['name'],
                                        'count_ok' => 0,
                                        'count_broken' => 0,
                                        'img' => getItemImagePathFast($item['name'], $itemImages)
                                    ];
                                }

                                if ($item['broken']) {
                                    $groupedItems[$protoId]['count_broken'] += $item['count'];
                                } else {
                                    $groupedItems[$protoId]['count_ok'] += $item['count'];
                                }
                            }
                            krsort($groupedItems, SORT_NUMERIC);
                            // Affichage
                            foreach ($groupedItems as $protoId => $data):
                                if (!$data['img']) continue;
                            ?>
                            <div class="recap-objets-item" data-item="<?= htmlspecialchars($data['name']) ?>">
                                <div class="recap-objets-count">
                                    <?= $data['count_ok'] ?>
                                    <?php if ($data['count_broken'] > 0) echo " (+".$data['count_broken'].")"; ?>
                                </div>
                                <img src="<?= htmlspecialchars($data['img']) ?>"
                                    alt="<?= htmlspecialchars($data['name']) ?>"
                                    title="<?= htmlspecialchars($protoId . ' : ' . $data['name']) ?>"" />
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php endforeach; ?>
            </div>
        </div>

    <!-- ========================= RECAP DES EXPEDITIONS  ========================== -->
        <div class="recap-expeditions">
            <div class="recap-cell">
                <b>Expéditions</b>

                <div class="expeditions-grid">
                    <?php foreach ($expeditions as $exp): ?>
                        <div class="expedition-line">
                            <label>
                                <input type="checkbox"
                                    class="expedition-checkbox"
                                    data-expedition-id="<?= $exp['id'] ?>" >
                                <?= htmlspecialchars($exp['label']) ?>
                                (<?= $exp['length'] ?>)
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div> <!-- class="sidebar" -->
</div> <!-- class="layout" -->


<script>
// =======================================
// VARIABLES
// =======================================
const EXPEDITIONS = <?= json_encode($expeditions, JSON_UNESCAPED_UNICODE) ?>;
const expeditionMap = {};
EXPEDITIONS.forEach(e => expeditionMap[e.id] = e);

const cellExpeditions = {}; // key = "x_y" => Set of expedition ids
function getCellKey(x, y) { return `${x}_${y}`; }

// =======================================
// AIDE ANGLE
// =======================================
function invertDirection(dir) {
    if(dir==='LEFT') return 'RIGHT';
    if(dir==='RIGHT') return 'LEFT';
    if(dir==='UP') return 'DOWN';
    if(dir==='DOWN') return 'UP';
    return dir;
}

// =======================================
// DESSIN
// =======================================
function drawSegment(cell, from, to) {
    const mid = '50%';
    function h(left,right){
        const d = document.createElement('div');
        d.className='exp-line exp-h';
        d.style.left = left; d.style.right=right;
        d.style.background='#000';
        cell.appendChild(d);
    }
    function v(top,bottom){
        const d = document.createElement('div');
        d.className='exp-line exp-v';
        d.style.top = top; d.style.bottom=bottom;
        d.style.background='#000';
        cell.appendChild(d);
    }

    // droites horizontales
    if ((from==='LEFT' && to==='RIGHT')||(from==='RIGHT' && to==='LEFT')){ h('0','0'); return; }
    // droites verticales
    if ((from==='UP' && to==='DOWN')||(from==='DOWN' && to==='UP')){ v('0','0'); return; }

    // centre → direction
    if(from==='CENTER'){
        if(to==='LEFT') h('0',mid);
        if(to==='RIGHT') h(mid,'0');
        if(to==='UP') v('0',mid);
        if(to==='DOWN') v(mid,'0');
        return;
    }

    // direction → centre
    if(to==='CENTER'){
        if(from==='LEFT') h('0',mid);
        if(from==='RIGHT') h(mid,'0');
        if(from==='UP') v(mid,'0');
        if(from==='DOWN') v('0',mid);
        return;
    }

    // angles
    if(from==='LEFT'||from==='RIGHT'){
        h(from==='LEFT'?'0':mid, from==='LEFT'?mid:'0');
        if(to==='UP') v(mid, '0');
        else if(to==='DOWN') v('0', mid);
    }
    if(from==='UP'||from==='DOWN'){
        v(from==='UP'?mid:'0', from==='UP'?'0':mid);
        if(to==='LEFT') h('0', mid);
        else if(to==='RIGHT') h(mid, '0');
    }
}

// =======================================
// DIRECTION ENTRE DEUX CASES
// =======================================
function getDirection(x1,y1,x2,y2){
    if(x1===x2 && y1===y2) return 'CENTER';
    if(x1===x2) return y2>y1?'DOWN':'UP';
    if(y1===y2) return x2>x1?'RIGHT':'LEFT';
    return x2>x1?'RIGHT':'LEFT';
}

// =======================================
// EXPAND PATH (cases intermédiaires)
// =======================================
function expandPath(path){
    if(!path||path.length===0) return [];
    const expanded=[];
    let [cx,cy]=path[0];
    expanded.push([cx,cy]);
    for(let i=1;i<path.length;i++){
        const [tx,ty]=path[i];
        const dx=Math.sign(tx-cx);
        const dy=Math.sign(ty-cy);
        while(cx!==tx||cy!==ty){
            if(cx!==tx) cx+=dx;
            if(cy!==ty) cy+=dy;
            expanded.push([cx,cy]);
        }
    }
    console.log(expanded);
    return expanded;
}

// =======================================
// UPDATE CELL
// =======================================
function updateCell(x,y){
    const cell=document.querySelector(`.cell[data-x="${x}"][data-y="${y}"]`);
    if(!cell) return;
    cell.querySelectorAll('.exp-line').forEach(e=>e.remove());

    const key=getCellKey(x,y);
    const expeditionsHere=cellExpeditions[key];
    if(!expeditionsHere) return;

    expeditionsHere.forEach(id=>{
        const expedition=expeditionMap[id];
        if(!expedition) return;
        const path=expandPath(expedition.path);

        for(let i=0;i<path.length;i++){
            const [cx,cy]=path[i];
            if(cx!==x||cy!==y) continue;

            const prev=path[i-1]||null;
            const next=path[i+1]||null;

            const rawFrom = prev ? getDirection(prev[0],prev[1],cx,cy) : 'CENTER';
            const from = prev ? invertDirection(rawFrom) : 'CENTER';
            const to = next ? getDirection(cx,cy,next[0],next[1]) : 'CENTER';

            // 🔹 LOG DÉTAILLÉ
            console.log('=== Cell ===');
            console.log('Cell coords:', cx, cy);
            console.log('Expedition ID:', id, expedition.label);
            console.log('Previous cell:', prev, 'Next cell:', next);
            console.log('Raw direction from prev→current:', rawFrom);
            console.log('Inverted from (entrance direction):', from);
            console.log('To (exit direction):', to);
            console.log('--------------------------');

            drawSegment(cell,from,to);
        }
    });
}

// =======================================
// APPLY EXPEDITION
// =======================================
function applyExpedition(expedition,enabled){
    const path=expandPath(expedition.path);
    path.forEach(([x,y])=>{
        const key=getCellKey(x,y);
        if(!cellExpeditions[key]) cellExpeditions[key]=new Set();
        if(enabled) cellExpeditions[key].add(expedition.id);
        else{
            cellExpeditions[key].delete(expedition.id);
            if(cellExpeditions[key].size===0) delete cellExpeditions[key];
        }
        updateCell(x,y);
    });
}

// =======================================
// LISTENER CHECKBOX
// =======================================
document.querySelectorAll('.expedition-checkbox').forEach(cb=>{
    cb.addEventListener('change',()=>{
        const expedition=expeditionMap[cb.dataset.expeditionId];
        if(!expedition) return;
        applyExpedition(expedition,cb.checked);
    });
});
</script>




<!-- ===================== Checkbox PA ===================== -->
<script>
// Fonction pour recalculer les bordures PA
function updatePABorders() {
    const checkedBoxes = document.querySelectorAll('.pa-toggle:checked');
    const activePAs = Array.from(checkedBoxes).map(cb => parseInt(cb.dataset.pa));
    
    const cells = document.querySelectorAll('.cell');

    // Supprime toutes les anciennes div
    cells.forEach(cell => {
        cell.querySelectorAll('.pa-border-top, .pa-border-bottom, .pa-border-left, .pa-border-right')
            .forEach(div => div.remove());
    });

    if (!activePAs.length) return;

    cells.forEach(cell => {
        const x = parseInt(cell.dataset.x);
        const y = parseInt(cell.dataset.y);
        const dist = (Math.abs(x) + Math.abs(y)) * 2;

        activePAs.forEach(pa => {
            if (dist !== pa) return;

            const neighbors = [
                {dx:0,dy:1,side:'top'},
                {dx:0,dy:-1,side:'bottom'},
                {dx:1,dy:0,side:'right'},
                {dx:-1,dy:0,side:'left'},
            ];

            neighbors.forEach(n => {
                const nx = x + n.dx;
                const ny = y + n.dy;
                const neighbor = document.querySelector(`.cell[data-x="${nx}"][data-y="${ny}"]`);
                const ndist = neighbor ? (Math.abs(nx)+Math.abs(ny))*2 : Infinity;
                if (ndist > pa) {
                    const div = document.createElement('div');
                    div.className = 'pa-border-' + n.side;
                    cell.appendChild(div);
                }
            });
        });
    });
}

// Événement sur **toutes les checkboxes PA**
document.querySelectorAll('.pa-toggle').forEach(cb => {cb.addEventListener('change', updatePABorders);});
updatePABorders();
</script>

<!-- ===================== Checkbox KM ===================== -->
<script>
function updateKMBorders() {
    const checkedBoxes = document.querySelectorAll('.km-toggle:checked');
    const activeKMs = Array.from(checkedBoxes).map(cb => parseInt(cb.dataset.km));
    
    const cells = document.querySelectorAll('.cell');

    // Supprime toutes les anciennes div KM
    cells.forEach(cell => {
        cell.querySelectorAll('.km-border-top, .km-border-bottom, .km-border-left, .km-border-right')
            .forEach(div => div.remove());
    });

    if (!activeKMs.length) return;

    cells.forEach(cell => {
        const x = parseInt(cell.dataset.x);
        const y = parseInt(cell.dataset.y);
        const dist = Math.round(Math.sqrt(x*x + y*y));

        activeKMs.forEach(km => {
            if (dist !== km) return;

            const neighbors = [
                {dx:0,dy:1,side:'top'},
                {dx:0,dy:-1,side:'bottom'},
                {dx:1,dy:0,side:'right'},
                {dx:-1,dy:0,side:'left'},
            ];

            neighbors.forEach(n => {
                const nx = x + n.dx;
                const ny = y + n.dy;
                const neighbor = document.querySelector(`.cell[data-x="${nx}"][data-y="${ny}"]`);
                const ndist = neighbor ? Math.round(Math.sqrt(nx*nx + ny*ny)) : Infinity;
                if (ndist > km) {
                    const div = document.createElement('div');
                    div.className = 'km-border-' + n.side;
                    cell.appendChild(div);
                }
            });
        });
    });
}

// Event sur toutes les checkboxes KM
document.querySelectorAll('.km-toggle').forEach(cb => {cb.addEventListener('change', updateKMBorders);});
updateKMBorders();
</script>

<!-- ===================== Checkbox Scrut ===================== -->
<script>
function getZone(x, y) {
    if (x === 0 && y === 0) return 'VILLE'; // ville
    if (y >= 0 && Math.abs(x) <= Math.floor(y / 2)) return 'N';
    if (y <= 0 && Math.abs(x) <= Math.floor(-y / 2)) return 'S';
    if (x <= 0 && Math.abs(y) <= Math.floor(-x / 2)) return 'O';
    if (x >= 0 && Math.abs(y) <= Math.floor(x / 2)) return 'E';
    if (x > 0 && y > 0) return 'NE';
    if (x < 0 && y > 0) return 'NO';
    if (x > 0 && y < 0) return 'SE';
    if (x < 0 && y < 0) return 'SO';
    return 'VILLE';
}

function updateScrutBorders() {
    const checked = document.getElementById('scrut-toggle').checked;
    const cells = document.querySelectorAll('.cell');

    // Supprime toutes les anciennes bordures Scrut
    cells.forEach(cell => {
        cell.querySelectorAll('.scrut-border-top,.scrut-border-bottom,.scrut-border-left,.scrut-border-right')
            .forEach(div => div.remove());
    });

    if (!checked) return;

    cells.forEach(cell => {
        const x = parseInt(cell.dataset.x);
        const y = parseInt(cell.dataset.y);
        const zone = getZone(x, y);

        const neighbors = [
            {dx:0,dy:1,side:'top'},
            {dx:0,dy:-1,side:'bottom'},
            {dx:1,dy:0,side:'right'},
            {dx:-1,dy:0,side:'left'},
        ];

        neighbors.forEach(n => {
            const nx = x + n.dx;
            const ny = y + n.dy;
            const neighbor = document.querySelector(`.cell[data-x="${nx}"][data-y="${ny}"]`);
            if (!neighbor) return;
            const neighborZone = getZone(nx, ny);
            if (zone !== neighborZone) {
                const div = document.createElement('div');
                div.className = 'scrut-border-' + n.side;
                cell.appendChild(div);
            }
        });
    });
}

// Event checkbox
document.getElementById('scrut-toggle').addEventListener('change', updateScrutBorders);
updateScrutBorders()
</script>

<!-- ===================== Checkbox Bâtiments ===================== -->
<script>
const buildingCells = [<?php foreach ($buildingsRecap as $group) {foreach ($group as $b) {echo '{x: '.(int)$b['x'].', y: '.(int)$b['y'].'},';}}?>];

function updateBuildingBorders() {
    const checked = document.getElementById('building-toggle').checked;
    const cells = document.querySelectorAll('.cell');

    // Supprime toutes les anciennes div building-border
    cells.forEach(cell => {
        cell.querySelectorAll('.building-border').forEach(div => div.remove());
    });

    if (!checked) return;

    buildingCells.forEach(b => {
        const cell = document.querySelector(`.cell[data-x="${b.x}"][data-y="${b.y}"]`);
        if (!cell) return;

        const div = document.createElement('div');
        div.className = 'building-border';
        cell.appendChild(div);
    });
}

// Event checkbox
document.getElementById('building-toggle').addEventListener('change', updateBuildingBorders);
updateBuildingBorders();
</script>

<!-- ===================== Checkbox Citoyens ===================== -->
<script>
const citizenCells = [<?php foreach ($citizens as $c) {echo '{x: '.$c['x'].', y: '.$c['y'].'},';}?>];

function updateCitizenBorders() {
    const checked = document.getElementById('citizen-toggle').checked;
    const cells = document.querySelectorAll('.cell');

    // Supprime toutes les anciennes div citizen-border
    cells.forEach(cell => {
        cell.querySelectorAll('.citizen-border').forEach(div => div.remove());
    });

    if (!checked) return;

    citizenCells.forEach(c => {
        const cell = document.querySelector(`.cell[data-x="${c.x}"][data-y="${c.y}"]`);
        if (!cell) return;

        const div = document.createElement('div');
        div.className = 'citizen-border';
        cell.appendChild(div);
    });
}

// Event checkbox
document.getElementById('citizen-toggle').addEventListener('change', updateCitizenBorders);
updateCitizenBorders();
</script>


<!-- ===================== Checkbox Objets ===================== -->
<script>
const itemImagesMap = <?= json_encode($itemImages) ?>;
const activeObjects = new Set();

// Équivalent JS exact de getItemImagePathFast()
function getItemImagePathFastJS(name, map) {
    const key = name.replace(/_#\d+$/, '');
    return map[key] || null;
}

// Clique sur les objets du récap
document.querySelectorAll('.recap-objets-item').forEach(itemDiv => {
    itemDiv.addEventListener('click', () => {
        const img = itemDiv.querySelector('img');
        if (!img) return;

        const path = getItemImagePathFastJS(img.alt, itemImagesMap);
        if (!path) return;

        // toggle actif
        itemDiv.classList.toggle('active-object');
        activeObjects.has(path) ? activeObjects.delete(path) : activeObjects.add(path);

        updateObjectBorders();
    });
});

// Met à jour les bordures sur la carte
function updateObjectBorders() {
    const cells = document.querySelectorAll('.cell');

    // Nettoyage
    cells.forEach(cell => {
        cell.querySelectorAll('.item-border').forEach(div => div.remove());
    });

    if (activeObjects.size === 0) return;

    cells.forEach(cell => {
        const itemsData = cell.dataset.items ? JSON.parse(cell.dataset.items) : {};
        let hasActiveObject = false;

        // itemsData = { catId: { path: {ok, broken} } }
        Object.values(itemsData).some(categoryItems =>
            Object.keys(categoryItems).some(path =>
                activeObjects.has(path) && (hasActiveObject = true)
            )
        );

        if (hasActiveObject) {
            const div = document.createElement('div');
            div.className = 'item-border';
            cell.appendChild(div);
        }
    });
}
</script>

<!-- ===================== Checkbox ZOO ===================== -->

<script>
const zooToggle = document.getElementById('zoo-toggle');
let zooRect = null;

if (zooToggle) {
    zooToggle.addEventListener('change', updateZooRectangle);
    updateZooRectangle(); // état initial
}

function updateZooRectangle() {
    const mapEl = document.querySelector('.map');
    if (!mapEl) return;

    // Supprime le rectangle existant
    if (zooRect) {
        zooRect.remove();
        zooRect = null;
    }

    if (!zooToggle.checked) return;

    // Coordonnées zoo
    const minX = -11;
    const maxX = -6;
    const minY = -11;
    const maxY = -5;

    // Récupère les cellules coins
    const cells = [...document.querySelectorAll('.cell')];

    const topLeft = cells.find(c => +c.dataset.x === minX && +c.dataset.y === maxY);
    const bottomRight = cells.find(c => +c.dataset.x === maxX && +c.dataset.y === minY);

    const mapRect = mapEl.getBoundingClientRect();
    const tlRect = topLeft.getBoundingClientRect();
    const brRect = bottomRight.getBoundingClientRect();

    zooRect = document.createElement('div');
    zooRect.className = 'zoo-rectangle';

    zooRect.style.left = (tlRect.left - mapRect.left) + 'px';
    zooRect.style.top = (tlRect.top - mapRect.top) + 'px';
    zooRect.style.width = (brRect.right - tlRect.left)-5 + 'px';
    zooRect.style.height = (brRect.bottom - tlRect.top) + 'px';

    mapEl.style.position = 'relative'; // sécurité
    mapEl.appendChild(zooRect);
}
</script>

<!-- ===================== HEAVY / LEGERS ===================== -->

<script>
function updateItemCounts() {
    const showHeavy = document.getElementById('filter-heavy').checked;
    const showLight = document.getElementById('filter-light').checked;

    document.querySelectorAll('.cell').forEach(cell => {
        const countEl = cell.querySelector('.item-count');
        if (!countEl) return;

        const heavy = parseInt(cell.dataset.heavy || 0);
        const light = parseInt(cell.dataset.light || 0);

        let total = 0;
        if (showHeavy) total += heavy;
        if (showLight) total += light;

        // n'affiche rien si total = 0 ou si aucune checkbox cochée
        countEl.textContent = total > 0 ? total : '';
    });
}

// exécution au chargement
window.addEventListener('DOMContentLoaded', updateItemCounts);

// écouteurs
document.getElementById('filter-heavy').addEventListener('change', updateItemCounts);
document.getElementById('filter-light').addEventListener('change', updateItemCounts);
</script>

<!-- ===================== Mouse-over récap des bâtiments ===================== -->
<script>
const buildingTooltip = document.getElementById('building-tooltip');

document.querySelectorAll('.building-tooltip-target').forEach(el => {
    el.addEventListener('mouseenter', () => {
        const raw = el.dataset.drops;
        if (!raw) return;

        let drops;
        try { drops = JSON.parse(raw); } 
        catch(err) { console.error('JSON invalide', raw); return; }

        if (!drops.length) { buildingTooltip.style.display = 'none'; return; }

        let html = '';
        drops.forEach(d => {
            html += `<div class="building-tooltip-line">
                        <img src="${d.img}" alt="" title="${d.pct}%">
                        <span>${d.pct}%</span>
                     </div>`;
        });

        buildingTooltip.innerHTML = html;

        const rect = el.getBoundingClientRect();
        buildingTooltip.style.left = (rect.right + 5 + window.scrollX) + 'px';
        buildingTooltip.style.top  = (rect.top + window.scrollY) + 'px';
        buildingTooltip.style.display = 'block';
    });

    el.addEventListener('mouseleave', () => {
        buildingTooltip.style.display = 'none';
    });
});
</script>


<!-- ========================= Mouse-over cases ========================== -->
<script>
const tooltip = document.getElementById('tooltip');

document.querySelectorAll('.cell').forEach(cell => {
    cell.addEventListener('mouseenter', () => {
        const discovery = cell.dataset.discovery;
        const categoryLabels = {
            1: 'Ress',
            2: 'Déco',
            3: 'Arme',
            4: 'Cont',
            5: 'Défs',
            6: 'Drog',
            7: 'Food',
            8: 'Autr',
        };
        if (discovery == 0) {
            tooltip.innerHTML = "Pas découverte";
        } else {
            const x = parseInt(cell.dataset.x);
            const y = parseInt(cell.dataset.y);
            const items = JSON.parse(cell.dataset.items || '{}'); // { "path/to/image.png": count }
            const citizens = JSON.parse(cell.dataset.citizens || '[]');
            const prototype = cell.dataset.prototype || '';
            const day_update = cell.dataset.day_update;
            const ruin_regen = cell.dataset.ruin_regen;

            let html = `<div style="display:flex; justify-content:space-between;">
                <span><strong>Coord :</strong> ${x} / ${y}</span>
                <span style="padding-left:10px;"><strong>Distance :</strong> ${Math.round(Math.sqrt(x*x + y*y))} km</span>
            </div>`;

            if (day_update) html += `<div>MAJ: J${day_update}</div>`;
            if (prototype) html += `<hr><div><strong>Bâtiment:</strong> ${prototype} (${ruin_regen > 0 ? 'plein' : 'vide'})</div>`;

            // Citoyens par 7
            html += `<hr><div><strong>Citoyens:</strong><br>`;
            if (citizens.length) {
                for (let i = 0; i < citizens.length; i += 7) {
                    html += citizens.slice(i, i + 7).map(c => c).join(', ') + '<br>';
                }
            } else html += 'Aucun<br>';
            html += `</div>`;

            // Objets avec images
            html += `<hr><div><strong><u>Objets :</u></strong><br>`;

            if (Object.keys(items).length) {
                for (const catId of Object.keys(categoryLabels)) {
                    if (!items[catId]) continue;

                    html += `<div><strong>${categoryLabels[catId]} : </strong><br>`;

                    let i = 0;
                    for (const [imgPath, counts] of Object.entries(items[catId])) {
                        const ok = counts.ok || 0;
                        const broken = counts.broken || 0;
                        const total = ok + broken;

                        if (total <= 0) continue;

                        const style = broken > 0
                            ? 'vertical-align:middle;margin:1px;padding:1px;border:1px solid red;box-sizing:content-box;'
                            : 'vertical-align:middle;margin:1px;';

                        html += `<img src="${imgPath}" width="16" height="16" style="${style}">`;

                        if (total > 1) {
                            html += `x${total} `;
                        }

                        i++;
                        if (i % 10 === 0) html += '<br>';
                    }

                    html += `</div>`;
                }
            } else {
                html += 'Aucun objet<br>';
            }

            html += `</div>`;

            tooltip.innerHTML = html;

            // position tooltip
            const rect = cell.getBoundingClientRect();
            tooltip.style.left = (rect.right + 5) + 'px';
            tooltip.style.top = (rect.top + window.scrollY) + 'px';
            tooltip.style.display = 'block';
        }
    });

    cell.addEventListener('mouseleave', () => {
        tooltip.style.display = 'none';
    });
});
</script>

</body>
</html>
