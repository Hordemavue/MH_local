<?php

/* =============================
   Paramètre ville
   ============================= */

$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    exit("URL attendue : http://localhost:8081/_veille.php?id=xx");
}

?>

<div style="position: relative; padding: 8px 12px; display: flex; align-items: center; min-height: unset;margin:1px;">

    <!-- PARTIE GAUCHE -->
    <?php
        $leftContent = "";
    ?>
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
        $rightContent = "";
    ?>
    <?php if (!empty($rightContent ?? '')) { ?>
        <div style="padding: 6px 12px;background: rgba(245,245,245,0.9);border-radius: 14px;border: 1px solid #b5b5b5;font-size: 13px;color: #333;box-shadow: 0 2px 5px rgba(0,0,0,0.08);margin-left: auto;white-space: nowrap;">
            <?= $rightContent ?>
        </div>
    <?php } ?>

</div>

<?php

/* =============================
   Connexion BDD
   ============================= */

$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* =============================
   Configuration affichage
   ============================= */

$extensionsIds = [132, 134];

$heavyPriority = [30, 362, 131, 34, 100, 209];
$lightPriority = [93, 92, 105, 66, 104, 98, 121, 115, 301, 76, 90, 32, 130, 111];

/* =============================
   Styles
   ============================= */
?>
<style>
table { border-collapse: collapse; width: 100%; }
td { border: 1px solid #ccc; padding: 6px; vertical-align: top; position: relative; }
.inventory-line {display: flex;align-items: center;flex-wrap: nowrap;}
.inventory {display: inline-grid;gap: 2px;margin-left:6px;}
.inventory span {width: 18px;height: 18px;border: 1px solid #999;display: flex;align-items: center;justify-content: center;}
.stats-line {font-size: 15px;display: flex;align-items: center;gap: 10px;min-height: 18px;margin-bottom:4px;}
.status-line {white-space: nowrap;}
.list-block {display: inline-block;margin-bottom: 12px;}
.lists-container {display: flex;flex-wrap: wrap;gap: 24px;margin-top:20px;}
.list {white-space: nowrap;padding-right:30px;}
.list-header {font-weight: bold;margin-bottom: 6px;}
</style>

<title>Veille : <?= htmlspecialchars($town_id) ?></title>

<?php


$stmt = $pdo->prepare("SELECT day FROM town WHERE id = ?");
$stmt->execute([$town_id]);
$currentDay = (int)$stmt->fetchColumn();

/* =============================
   Images items
   ============================= */

$itemImages = [];
foreach (glob(__DIR__ . "/build/images/item/item_*.gif") as $file) {
    if (preg_match('/item_(.+?)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $itemImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}
function getItemImagePathFast($name, $map) {
    return $map[preg_replace('/_#\d+$/', '', $name)] ?? null;
}

/* =============================
   Images statuts
   ============================= */

$statusImages = [];
foreach (glob(__DIR__ . "/build/images/status/status_*.gif") as $file) {
    if (preg_match('/status_(.+?)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $statusImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

/* =============================
   Items ignorés
   ============================= */

$ignoredItems = [
    'shoe','bike','basic_suit','basic_suit_dirt','shaman','shield',
    'tamed_pet','tamed_pet_drug','tamed_pet_off',
    'vest_off','vest_on','keymol','pelle','surv_book'
];

/* =============================
   Supers E1–E4
   ============================= */

$skillMap = [
    37 => 'E1', 38 => 'E2', 39 => 'E3', 40 => 'E4',
];

/* =============================
   Requêtes
   ============================= */

$watchStmt = $pdo->prepare("
    SELECT cw.citizen_id
    FROM citizen_watch cw
    JOIN citizen c ON c.id = cw.citizen_id
    JOIN user u ON u.id = c.user_id
    WHERE cw.town_id = ? AND cw.day = ?
    ORDER BY u.name ASC
");$citizenStmt = $pdo->prepare("SELECT user_id, inventory_id, properties_id FROM citizen WHERE id=?");
$nameStmt    = $pdo->prepare("SELECT name FROM user WHERE id=?");
$propsStmt   = $pdo->prepare("SELECT props FROM citizen_properties WHERE id=?");

$itemStmt = $pdo->prepare("
    SELECT 
        ip.id,
        ip.name,
        ip.heavy
    FROM item it
    JOIN item_prototype ip ON ip.id = it.prototype_id
    WHERE it.inventory_id = ?
");

/* =============================
   Veilleurs
   ============================= */

$watchStmt->execute([$town_id, $currentDay]);
$citizens = $watchStmt->fetchAll(PDO::FETCH_COLUMN);
$nbVeilleurs = count($citizens);

/* =============================
   Statuts (SEULEMENT 13 & 16)
   ============================= */

$citizenStatuses = [];
$statusTotals    = [];
$statusNamesById = [];

if ($citizens) {

    $in = implode(',', array_fill(0, count($citizens), '?'));

    $stmt = $pdo->prepare("
        SELECT 
            ccs.citizen_id, 
            cs.id, 
            cs.name,
            cs.night_watch_defense_bonus,
            cs.night_watch_death_chance_penalty
        FROM citizen_citizen_status ccs
        JOIN citizen_status cs ON cs.id = ccs.citizen_status_id
        WHERE (
            cs.night_watch_defense_bonus <> 0
            OR cs.night_watch_death_chance_penalty <> 0
        )
        AND ccs.citizen_id IN ($in)
    ");

    $stmt->execute($citizens);

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $cid = $r['citizen_id'];
        $sid = (int)$r['id'];

        $citizenStatuses[$cid][] = [
            'id'   => $sid,
            'name' => $r['name']
        ];

        // Initialisation dynamique des totaux
        if (!isset($statusTotals[$sid])) {
            $statusTotals[$sid] = 0;
        }

        $statusNamesById[$sid] = $r['name'];
    }
}

/* =============================
   Totaux
   ============================= */

$itemTotals   = [];

/* =============================
   Affichage
   ============================= */

echo "<div class='list-block'>";

foreach ($citizens as $i => $cid) {

    $citizenStmt->execute([$cid]);
    [$uid, $invId, $properties_id] = $citizenStmt->fetch(PDO::FETCH_NUM);

    $nameStmt->execute([$uid]);
    $pseudo = $nameStmt->fetchColumn();

    /* ---- Super ---- */
    $super = '';
    if ($properties_id) {
        $propsStmt->execute([$properties_id]);
        $props = json_decode($propsStmt->fetchColumn(), true);
        if (!empty($props['skills']['list'])) {
            foreach ($props['skills']['list'] as $sid) {
                if (isset($skillMap[$sid])) {
                    $super .= $skillMap[$sid];
                }
            }
        }
    }

    /* ---- Capacité ---- */
    $capacity = 4;
    if (preg_match('/E([1-4])/', $super, $m)) {
        $capacity += [0,1,2,2,3][$m[1]];
    }

    /* ---- Inventaire ---- */
    $itemStmt->execute([$invId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $inventoryItems = [];

    echo "<div class='stats-line'>";
    echo "<strong>{$pseudo}</strong>";

    /* ---- Bonus items ---- */
    $capacityBonusItems = [
        'cart' => 3,
        'bag' => 2,
        'bagxl' => 3,
        'pocket_belt' => 2,
    ];

$inventoryItems = [];
$bonusItems      = [];
$extraCapacity   = 0;

foreach ($items as $it) {
    $base = preg_replace('/_#\d+$/', '', $it['name']);

    // Objets ignorés (affichés à gauche, pas comptés)
    if (in_array($base, $ignoredItems, true)) {
        $img = getItemImagePathFast($it['name'], $itemImages);
        if ($img) echo "<img src='$img' width='16'>";
        continue;
    }

    // Objets qui donnent de la capacité → affichés en premier
    if (isset($capacityBonusItems[$base])) {
        $extraCapacity += $capacityBonusItems[$base];
        $bonusItems[] = $it; // on stocke pour les afficher en premier
        continue;
    }

    // Objets normaux → prennent une place
    $inventoryItems[] = $it;
}

// Fusion bonusItems + inventoryItems pour l'affichage
$inventoryItems = array_merge($bonusItems, $inventoryItems);

$totalCapacity = $capacity + $extraCapacity;

echo "<div class='inventory' style='grid-template-columns:repeat($totalCapacity,18px)'>";

for ($s = 0; $s < $totalCapacity; $s++) {
    echo "<span>";
    if (isset($inventoryItems[$s])) {

        $item = $inventoryItems[$s];
        $img  = getItemImagePathFast($item['name'], $itemImages);

        if ($img) {

            echo "<img src='$img' width='16' title='ID: {$item['id']}'>";

            $itemTotals[$item['id']] = ($itemTotals[$item['id']] ?? 0) + 1;
        }
    }
    echo "</span>";
}

echo "</div>";


    /* ---- Statuts ---- */
    if (!empty($citizenStatuses[$cid])) {
        echo "<span class='status-line'>";
        foreach ($citizenStatuses[$cid] as $st) {
            $sid  = $st['id'];
            $name = $st['name'];

            if (isset($statusImages[$name])) {
                echo "<img src='{$statusImages[$name]}' width='16'>";
                $statusTotals[$sid]++;
                $statusNamesById[$sid] = $name; // ← LIGNE CRUCIALE
            }
        }
        echo "</span>";
    }

    echo "</div>";
}

echo "</div>";


/* =============================
   Chargement de tous les prototypes
   ============================= */

$allPrototypes  = [];
$itemImagesById = [];

/* 1️⃣ Récupérer tous les prototypes */
$protoStmt = $pdo->query("
    SELECT id, name, heavy
    FROM item_prototype
");

while ($row = $protoStmt->fetch(PDO::FETCH_ASSOC)) {

    $id   = (int)$row['id'];
    $name = $row['name'];

    $allPrototypes[$id] = [
        'name'  => $name,
        'heavy' => (int)$row['heavy']
    ];

    // Associer image par ID
    if (isset($itemImages[$name])) {
        $itemImagesById[$id] = $itemImages[$name];
    }
}



echo "<div class='lists-container'>";


/* ============================================================
   EXTENSIONS
   ============================================================ */

echo "<div class='list'>";
echo "<div class='list-header'>Extensions</div>";

// Affichage des 2 extensions prioritaires uniquement
foreach ($extensionsIds as $id) {

    if (!isset($allPrototypes[$id])) continue;

    $item = $allPrototypes[$id];
    $img  = getItemImagePathFast($item['name'], $itemImages);
    if (!$img) continue;

    $count = $itemTotals[$id] ?? 0;

    echo "<div>";
    echo "<img src='{$img}' width='16'>";
    if ($count > 0) echo " : {$count}";
    echo "</div>";
}

echo "</div>";



/* ============================================================
   ENCOMBRANTS (heavy = 1)
   ============================================================ */

echo "<div class='list'>";
echo "<div class='list-header'>Encombrants</div>";

// Affichage prioritaire
foreach ($heavyPriority as $id) {

    if (!isset($allPrototypes[$id])) continue;
    if ($allPrototypes[$id]['heavy'] != 1) continue;

    $img = getItemImagePathFast($allPrototypes[$id]['name'], $itemImages);
    if (!$img) continue;

    $count = $itemTotals[$id] ?? 0;

    echo "<div>";
    echo "<img src='{$img}' width='16'>";
    if ($count > 0) echo " : {$count}";
    echo "</div>";
}

echo "<br>";

// Affichage des autres heavy présents dans la veille mais non prioritaires
foreach ($itemTotals as $id => $count) {

    if (!isset($allPrototypes[$id])) continue;
    if ($allPrototypes[$id]['heavy'] != 1) continue;
    if (in_array($id, $heavyPriority)) continue;

    $img = getItemImagePathFast($allPrototypes[$id]['name'], $itemImages);
    if (!$img) continue;

    echo "<div>";
    echo "<img src='{$img}' width='16'>";
    if ($count > 0) echo " : {$count}";
    echo "</div>";
}

echo "</div>";



/* ============================================================
   LÉGERS (heavy = 0)
   ============================================================ */

echo "<div class='list'>";
echo "<div class='list-header'>Légers</div>";

// Affichage prioritaire
foreach ($lightPriority as $id) {

    if (!isset($allPrototypes[$id])) continue;
    if ($allPrototypes[$id]['heavy'] != 0) continue;

    $img = getItemImagePathFast($allPrototypes[$id]['name'], $itemImages);
    if (!$img) continue;

    $count = $itemTotals[$id] ?? 0;

    echo "<div>";
    echo "<img src='{$img}' width='16'>";
    if ($count > 0) echo " : {$count}";
    echo "</div>";
}

echo "<br>";

// Affichage des autres light présents dans la veille mais non prioritaires
foreach ($itemTotals as $id => $count) {

    if (!isset($allPrototypes[$id])) continue;
    if ($allPrototypes[$id]['heavy'] != 0) continue;
    if (in_array($id, $lightPriority)) continue;

    $img = getItemImagePathFast($allPrototypes[$id]['name'], $itemImages);
    if (!$img) continue;

    echo "<div>";
    echo "<img src='{$img}' width='16'>";
    if ($count > 0) echo " : {$count}";
    echo "</div>";
}

echo "</div>";



/* ============================================================
   STATUTS (inchangé)
   ============================================================ */

echo "<div class='list'>";
echo "<div class='list-header'>Statuts</div>";

foreach ($statusTotals as $sid => $count) {

    if ($count > 0 && isset($statusNamesById[$sid])) {

        $name = $statusNamesById[$sid];

        if (isset($statusImages[$name])) {

            echo "<div>
                <img src='{$statusImages[$name]}' width='16'>
                : {$count} / {$nbVeilleurs}
            </div>";
        }
    }
}

echo "</div>";

echo "</div>"; // fermeture lists-container
