<?php
$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_perso_citoyens.php?id=xx";
    exit;
}

// On actualise les inventaires 

$cache_done = (int)($_GET['cache_done'] ?? 0);
if ($town_id && !$cache_done) {
    #header("Location: _refresh_town_cache.php?id={$town_id}");
    #exit;
}

/* ==========================
   IDS DES CITOYENS
   ========================== */
$userStmt = $pdo->prepare("SELECT user_id FROM citizen WHERE town_id = ? AND alive = 1");
$userStmt->execute([$town_id]);
$user_ids = $userStmt->fetchAll(PDO::FETCH_COLUMN);
sort($user_ids, SORT_NUMERIC);

/* ==========================
   REQUÊTES
   ========================== */
$nameStmt = $pdo->prepare("SELECT name FROM user WHERE id = ?");

$citizenInfoStmt = $pdo->prepare("
    SELECT ap, sp, bp, banished, camping_counter, profession_id
    FROM citizen
    WHERE user_id = ?
    AND town_id = ?
    AND alive = 1
");

$itemStmt = $pdo->prepare("
    SELECT ip.name
    FROM citizen c
    JOIN item it ON it.inventory_id = c.inventory_id
    JOIN item_prototype ip ON ip.id = it.prototype_id
    WHERE c.user_id = ?
    AND town_id = ?
    AND c.alive = 1
    ORDER BY ip.id
");

$propertiesStmt = $pdo->prepare("
    SELECT properties_id 
    FROM citizen 
    WHERE user_id = ? 
    AND town_id = ?
    AND alive = 1
    LIMIT 1
");

$propsStmt = $pdo->prepare("
    SELECT props 
    FROM citizen_properties 
    WHERE id = ? 
    LIMIT 1
");


$citizenHomeStmt = $pdo->prepare("
    SELECT chest_id, prototype_id, additional_defense, additional_storage, temporary_defense
    FROM citizen_home
    WHERE id = ?
");

$homeUpgradesStmt = $pdo->prepare("
    SELECT prototype_id, level
    FROM citizen_home_upgrade
    WHERE home_id = ?
");

$chestItemsStmt = $pdo->prepare("
    SELECT ip.name
    FROM item it
    JOIN item_prototype ip ON ip.id = it.prototype_id
    WHERE it.inventory_id = ?
    ORDER BY ip.id
");

$posStmt = $pdo->prepare("
    SELECT id, zone_id
    FROM citizen
    WHERE user_id = ?
    AND town_id = ?
    AND alive = 1
");

$heroicStmt = $pdo->prepare("
    SELECT heroic_action_prototype_id 
    FROM citizen_heroic_action_prototype 
    WHERE heroic_action_prototype_id < 11 
    AND citizen_id = ?
");

$digStmt = $pdo->prepare("
    SELECT timestamp
    FROM dig_timer
    WHERE zone_id=?
    AND passive=0
    AND citizen_id=?
");

$zoneStmt = $pdo->prepare("
    SELECT x, y, digs 
    FROM zone
    WHERE id = ?
");

$stmt = $pdo->prepare("SELECT day FROM town WHERE id = ?");
$stmt->execute([$town_id]);
$currentDay = (int)$stmt->fetchColumn();

/* ==========================
   SUPER MAP
   ========================== */
$skillMap = [
    25 => 'S1', 26 => 'S2', 27 => 'S3', 28 => 'S4',
    29 => 'U1', 30 => 'U2', 31 => 'U3', 32 => 'U4',
    33 => 'P1', 34 => 'P2', 35 => 'P3', 36 => 'P4',
    37 => 'E1', 38 => 'E2', 39 => 'E3', 40 => 'E4',
    41 => 'R1', 42 => 'R2', 43 => 'R3', 44 => 'R4',
];

/* ==========================
   MAP user_id -> citizen.id
   ========================== */
$citizenIdByUser = [];

if (!empty($user_ids)) {
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));

    $stmt = $pdo->prepare("
        SELECT id, user_id
        FROM citizen
        WHERE user_id IN ($placeholders)
        AND alive = 1
    ");
    $stmt->execute($user_ids);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $citizenIdByUser[(int)$row['user_id']] = (int)$row['id'];
    }
}

/* ==========================
   STATUS DES CITOYENS
   ========================== */
$citizenStatuses = [];

$citizenIds = array_values($citizenIdByUser);

if (!empty($citizenIds)) {
    $placeholders = implode(',', array_fill(0, count($citizenIds), '?'));

    $Statutstmt = $pdo->prepare("
        SELECT ccs.citizen_id, cs.name
        FROM citizen_citizen_status ccs
        JOIN citizen_status cs ON cs.id = ccs.citizen_status_id
        WHERE cs.hidden = 0
        AND ccs.citizen_id IN ($placeholders)
    ");

    $Statutstmt->execute($citizenIds);

    while ($row = $Statutstmt->fetch(PDO::FETCH_ASSOC)) {
        $citizenStatuses[(int)$row['citizen_id']][] = $row['name'];
    }
}


/* ==========================
   ITEMS
   ========================== */
$capacityBonusItems = [
    'cart' => 3,
    'bag' => 2,
    'bagxl' => 3,
    'pocket_belt' => 2,
];

$ignoredItems = ['shoe', 'bike', 'basic_suit', 'basic_suit_dirt', 'shaman', 'shield', 'tamed_pet', 'tamed_pet_drug', 'tamed_pet_off', 'vest_off', 'vest_on', 'keymol', 'pelle', 'surv_book'];

/* ==========================
   IMAGES ITEMS
   ========================== */
$itemImages = [];
foreach (glob(__DIR__ . "/build/images/item/item_*.gif") as $file) {
    if (preg_match('/item_(.+?)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $itemImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

function getItemImagePathFast($name, $map) {
    $key = preg_replace('/_#\d+$/', '', $name);
    return $map[$key] ?? null;
}

/* ==========================
   IMAGES MAISONS & UPGRADES
   ========================== */
$homeImages = [];
foreach (glob(__DIR__ . "/build/images/home/*.gif") as $file) {
    if (preg_match('/([^\/]+)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $homeImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

function getHomeImage($key, $map) {
    return $map[$key] ?? null;
}

/* MAP upgrades -> image key */
$homeUpgradeMap = [
    1 => 'curtain',
    2 => 'lab',
    3 => 'kitchen',
    4 => 'alarm',
    5 => 'rest',
    6 => 'lock',
    7 => 'fence',
    8 => 'chest',
    9 => 'defense',
];


/* ==========================
   IMAGES ICONS (AP / SP / BP / HUMAN / BANISHED)
   ========================== */
$iconImages = [];
foreach (glob(__DIR__ . "/build/images/icons/*.gif") as $file) {
    if (preg_match('/([^\/]+)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $iconImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

function getIconPath($key, $map) {
    return $map[$key] ?? null;
}

/* ==========================
   IMAGES CAMPING
   ========================== */
$campImages = [];
foreach (glob(__DIR__ . "/build/images/pictos/*.gif") as $file) {
    if (preg_match('/([^\/]+)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $campImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

function getCampPath($key, $map) {
    return $map[$key] ?? null;
}

/* ==========================
   IMAGES STATUS
   ========================== */
$statusImages = [];
foreach (glob(__DIR__ . "/build/images/status/status_*.gif") as $file) {
    if (preg_match('/status_(.+?)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $statusImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

function getStatusImagePath($name, $map) {
    return $map[$name] ?? null;
}


/* Habitations fortifiées construites */
$stmt = $pdo->prepare("select complete from building where town_id=? and prototype_id=78;");
$stmt->execute([$town_id]);
$hf_construite = $stmt->fetch(PDO::FETCH_ASSOC);

/* ==========================
   CALCUL DE LA DEFENSE HABITATION
   ========================== */
function computeHomeDefense($home, $upgrades, $hf_construite, $profession) {
    $def = (int)$home['additional_defense']
         + (int)$home['temporary_defense']
         + pow($home['prototype_id'] - 1, 2);

    /* Renfort (prototype 7) */
    if (isset($upgrades[9])) {
        $lvl = $upgrades[9];
        $map = [1=>1,2=>2,3=>3,4=>4,5=>5,6=>6,7=>8,8=>10,9=>12,10=>14];
        $def += $map[$lvl] ?? 0;
    }

    if (isset($upgrades[7])) {
        $def += 3;
    }

    if (!empty($hf_construite['complete'])) {
        $def += 4;
    }

    $def += match ($profession) {
        1, 2 => 0,
        4    => 3,
        default => 2,
    };

    return $def;
}

/* ==========================
   CALCUL DE LA PLACE EN COFFRE
   ========================== */
function computeChestCapacity($super, $home, $upgrades) {
    $cap = 4;

    /* SUPER */
    if (preg_match('/U[34]/', $super)) $cap += 1;
    if (strpos($super, 'E1') !== false) $cap += 1;
    if (strpos($super, 'E2') !== false) $cap += 2;
    if (preg_match('/E[34]/', $super)) $cap += 3;

    /* Storage bonus */
    $cap += (int)$home['additional_storage'];

    /* Chest upgrade (prototype 8) */
    if (isset($upgrades[8])) {
        $cap += (int)$upgrades[8];
    }

    return $cap;
}

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Citoyens : <?= htmlspecialchars($town_id) ?></title>
<style>
table { border-collapse: collapse; width: 100%; }
td { border: 1px solid #ccc; padding: 6px; vertical-align: top; position: relative; }
.user-block {display: flex;gap: 10px;width: 100%;height: 100%;position: relative;}
.user-info {flex: 0 0 60%;display: flex;flex-direction: column;height: 100%;}
.user-stats {flex: 0 0 40%;display: flex;flex-direction: column;position: relative;height: 100%;}
.user-stats .camping {position: absolute;top: 2px;right: 2px;display: flex;align-items: center;font-size: 15px;margin-right: 10px;gap: 2px;}
.inventory-line {display: flex;align-items: center;flex-wrap: nowrap;}
.inventory {display: inline-grid;gap: 2px;}
.inventory span {width: 18px;height: 18px;border: 1px solid #999;display: flex;align-items: center;justify-content: center;}
.stats-line {font-size: 15px;display: flex;align-items: center;gap: 10px;min-height: 18px;}
.status-line {margin-top: 2px;white-space: nowrap;min-height: 18px}
.list-block {display: inline-block;margin-bottom: 12px;}
.list-row {display: grid;grid-template-columns: 1fr 1fr;gap: 12px;}
.list-header {font-weight: bold;margin-bottom: 6px;}
.lists-container {display: flex;flex-wrap: wrap;gap: 12px;}
.list {white-space: nowrap;}
.list {padding-right:30px;}
</style>
</head>
<body style="margin-top:0px;margin-bottom:0px;">
<table>
<tr>
<?php
$col = 0;
$total_ap = 0;
$total_cp = 0;
foreach ($user_ids as $uid) {
    if ($col === 4) { echo "</tr><tr>"; $col = 0; }

    echo "<td>";

    $nameStmt->execute([$uid]);
    $name = $nameStmt->fetchColumn();

    $citizenInfoStmt->execute([$uid, $town_id]);
    $info = $citizenInfoStmt->fetch(PDO::FETCH_ASSOC);

    $ap = (int)$info['ap'];
    $total_ap += $ap;
    $sp = (int)$info['sp'];
    $bp = (int)$info['bp'];
    $total_cp += $bp;
    $banished = (int)$info['banished'];
    $camping = (int)$info['camping_counter'];
    $profession = (int)$info['profession_id'];

    echo "<div class='user-block'>";

    /* ================= LEFT ================= */
    echo "<div class='user-info'>";

    // LIGNE 1 : pseudo + super
    $statusImg = $banished ? getIconPath('banished', $iconImages) : getIconPath('small_human', $iconImages);
    echo "<div>";
    if ($statusImg) echo "<img src='{$statusImg}' width='16' style='vertical-align:middle;margin-right:4px'>";
    echo "<strong>" . htmlspecialchars($name) . "</strong>";

    $super = '';
    $propertiesStmt->execute([$uid, $town_id]);
    $pid = $propertiesStmt->fetchColumn();
    if ($pid) {
        $propsStmt->execute([$pid]);
        $props = json_decode($propsStmt->fetchColumn(), true);
        if (!empty($props['skills']['list'])) {
            foreach ($props['skills']['list'] as $sid) {
                if (isset($skillMap[$sid])) $super .= $skillMap[$sid];
            }
        }
    }
    if ($super !== '') echo "<span style='font-size:11px;color:#555;margin-left:4px'>: {$super}</span>";
    echo "</div>";

    // LIGNE 2 : inventaire
    $itemStmt->execute([$uid, $town_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $capacity = 4;
    if (preg_match('/E([1-4])/', $super, $m)) $capacity += [0,1,2,2,3][$m[1]];

    $inventoryItems = [];
    $extraCapacity = 0;

    echo "<div class='inventory-line'>";
    foreach ($items as $i => $item) {
        $base = preg_replace('/_#\d+$/', '', $item['name']);
        if (in_array($base, $ignoredItems, true)) {
            $img = getItemImagePathFast($item['name'], $itemImages);
            if ($img) echo "<img src='{$img}' width='16' style='margin-left:2px'>";
            continue;
        }
        if (isset($capacityBonusItems[$base])) $extraCapacity += $capacityBonusItems[$base];
        $inventoryItems[] = $item;
    }

    $total = $capacity + $extraCapacity;

    echo "<div class='inventory' style='margin-left:4px;grid-template-columns:repeat({$total},18px)'>";
    for ($i=0;$i<$total;$i++) {
        echo "<span>";
        if (isset($inventoryItems[$i])) {
            $img = getItemImagePathFast($inventoryItems[$i]['name'], $itemImages);
            if ($img) echo "<img src='{$img}' width='16'>";
        }
        echo "</span>";
    }
    echo "</div>";
    echo "</div>";

    // LIGNE 3 : maison + coffre
    $citizenHomeStmt->execute([$citizenIdByUser[$uid]]);
    $home = $citizenHomeStmt->fetch(PDO::FETCH_ASSOC);

    if ($home) {
        $homeUpgradesStmt->execute([$citizenIdByUser[$uid]]);
        $upgradesRaw = $homeUpgradesStmt->fetchAll(PDO::FETCH_ASSOC);

        $upgrades = [];
        foreach ($upgradesRaw as $u) {
            $upgrades[(int)$u['prototype_id']] = (int)$u['level'];
        }

        $defense = computeHomeDefense($home, $upgrades, $hf_construite, $profession);
        $homeImgKey = 'home_lv' . ($home['prototype_id'] - 1);
        $homeImg = getHomeImage($homeImgKey, $homeImages);

        echo "<div style='margin-top:5px;display:flex;align-items:center;gap:6px'>";

        // Maison + défense
        if ($homeImg) echo "<img src='{$homeImg}' width='22'>";
        echo "<strong>" . sprintf('%02d', $defense) . "</strong>";

        // Coffre
        $capacity = computeChestCapacity($super, $home, $upgrades);
        $chestItemsStmt->execute([$home['chest_id']]);
        $chestItems = $chestItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<div class='inventory' style='grid-template-columns:repeat({$capacity},18px)'>";
        for ($i = 0; $i < $capacity; $i++) {
            echo "<span>";
            if (isset($chestItems[$i])) {
                $name = $chestItems[$i]['name'];
                $img = getItemImagePathFast($name, $itemImages);
                if ($img) echo "<img src='{$img}' width='16'>";
            }
            echo "</span>";
        }
        echo "</div>";

        echo "</div>"; // fin maison + coffre
    }

    /* ================= LIGNE 4 ================= */
    // LEFT : actions héroïques
    $cid = $citizenIdByUser[$uid] ?? null;
    $heroicStmt->execute([$cid]);
    $heroicsRaw = $heroicStmt->fetchAll(PDO::FETCH_COLUMN);

    $heroicMap = [1=>'RDH',2=>'TA1',3=>'US',4=>'SS',5=>'VLM',6=>'VLM',7=>'TA2',8=>'TA3',9=>'TA4',10=>'Sauv'];
    $adjustedHeroics = [];
    foreach ($heroicsRaw as $hid) {
        $nameHero = $heroicMap[$hid] ?? $hid;
        if (preg_match('/U([1-4])/', $super, $m)) { $u=(int)$m[1]; if($hid===10)$nameHero.=" ({$u})"; }
        if (preg_match('/E([1-4])/', $super, $m)) {
            $e = (int)$m[1];
            if ($hid === 3) {
                $map = [1 => 2, 2 => 3, 3 => 4, 4 => 4];
                $nameHero .= " ({$map[$e]})";
            }

            if ($hid === 4) {
                $map = [1 => 22, 2 => 24, 3 => 44, 4 => 46];
                $nameHero .= " ({$map[$e]})";
            }
        }
        if (preg_match('/R([1-4])/', $super, $m)) { $r=(int)$m[1]; if($hid===1)$nameHero.=" (".(9+2*($r-1)).")"; }
        $adjustedHeroics[] = $nameHero;
    }

    if (!empty($adjustedHeroics)) {
        echo "<div style='margin-top:5px;font-size:13px;'>".implode(" / ", $adjustedHeroics)."</div>";
    }

    echo "</div>"; // FIN user-info LEFT

    /* ================= RIGHT ================= */
    echo "<div class='user-stats'>";

    // PA / SP / BP
    echo "<div class='stats-line'>";
    if ($p=getIconPath('ap_small_fr',$iconImages)) echo "<img src='{$p}' width='14'> {$ap}";
    if ($sp>0 && ($p=getIconPath('sp_small_fr',$iconImages))) echo "<span style='margin-left:10px'><img src='{$p}' width='14'> {$sp}</span>";
    $hasKeymol = false;
    foreach ($items as $item) { if ($item['name']==='keymol_#00') { $hasKeymol=true; break; } }
    if ($hasKeymol && ($p=getIconPath('bp_small_fr',$iconImages))) echo "<span style='margin-left:10px'><img src='{$p}' width='14'> {$bp}</span>";
    echo "</div>";

    // Camping
    if ($camping > 0 && ($campImg = getCampPath('r_camp', $campImages))) {
        echo "<div class='camping'>{$camping}<img src='{$campImg}' width='14'></div>";
    }

    // Status
    echo "<div class='status-line'>";
    if ($cid && !empty($citizenStatuses[$cid])) {
        foreach ($citizenStatuses[$cid] as $statusName) {
            if (isset($statusImages[$statusName])) {
                echo "<img src='{$statusImages[$statusName]}' width='16' style='margin-right:4px'>";
            }
        }
    }
    echo "</div>";

    // Améliorations maison dans RIGHT (triées par prototype_id)
    if ($home && !empty($upgrades)) {
        ksort($upgrades); // Tri par prototype_id
        echo "<div style='margin-top:3px;display:flex;flex-wrap:wrap;gap:4px'>";
        foreach ($upgrades as $pid => $lvl) {
            if ($lvl > 0 && isset($homeUpgradeMap[$pid])) {
                $img = getHomeImage($homeUpgradeMap[$pid], $homeImages);
                if ($img) echo "<span><img src='{$img}' width='16'> {$lvl}</span>";
            }
        }
        echo "</div>";
    }
    else {echo "<div class='status-line'>&nbsp;</div>";}

    // RIGHT : position + prochaine fouille
    $posStmt->execute([$uid, $town_id]);
    $pos = $posStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pos['zone_id']) {
        echo "<div style='margin-top:5px;font-size:13px;'>En ville</div>";
    } else {
        $zoneStmt->execute([$pos['zone_id']]);
        $zone = $zoneStmt->fetch(PDO::FETCH_ASSOC);

        $digStmt->execute([$pos['zone_id'], $pos['id']]);
        $digRaw = $digStmt->fetch(PDO::FETCH_COLUMN);

        $digTime = $digRaw ? strtotime($digRaw) : null;

        $distance = round(sqrt($zone['x'] * $zone['x'] + $zone['y'] * $zone['y']));
        
        $currentHour = (int)date('H');
        
        $danger = ($distance > 2 && (int)$zone['digs'] === 0);

        $style = "margin-top:5px;font-size:13px;";
        if ($danger) {
            $style .= "color:red;font-weight:bold;";
        }
        // -----------------------

        echo "<div style='{$style}'>";
        echo "Pos: ({$zone['x']},{$zone['y']})";
        if ($digTime) echo " / Fouille: ".date('H:i:s',$digTime);
        echo "</div>";
    }



    echo "</div>"; // user-stats RIGHT
    echo "</div>"; // user-block
    echo "</td>";

    $col++;
}
?>


<div style="position: relative; padding: 8px 12px; display: flex; align-items: center; min-height: unset;">

    <!-- PARTIE GAUCHE -->
    <?php
        $leftContent = "PA restants : <strong>" . ($total_ap ?? 0) . "</strong> | PC restants : <strong>" . ($total_cp ?? 0) . "</strong>";
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


</table>

<?php

// =============================================================================================================
// Commencement des listes
// =============================================================================================================


// Liste des fouilles

$results = [];

$userStmt->execute([$town_id]);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {

    /* citizen.id + zone_id */
    $posStmt->execute([$u['user_id'], $town_id]);
    $citizen = $posStmt->fetch(PDO::FETCH_ASSOC);

    if (!$citizen) {
        continue;
    }

    $citizen_id = $citizen['id'];
    $zone_id    = $citizen['zone_id'];

    /* fouilles */
    $digStmt->execute([$zone_id, $citizen_id]);
    $digs = $digStmt->fetchAll(PDO::FETCH_ASSOC);

    $zoneStmt->execute([$zone_id]);
    $zone = $zoneStmt->fetch(PDO::FETCH_ASSOC);

    if (!$zone) {continue;};

    $distance = round(sqrt(($zone['x'] ** 2) + ($zone['y'] ** 2)));

    //if ($currentHour < 22){if ($distance < 3) {continue;}}

    $nameStmt->execute([$u['user_id']]);
    $userRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
    $username = $userRow ? $userRow['name'] : '???';

    foreach ($digs as $dig) {
        $results[] = [
            'user'      => $username,
            'time'      => substr($dig['timestamp'], 11, 8),
            'sort'      => substr($dig['timestamp'], 11, 8),
            'exhausted' => ((int)$zone['digs'] === 0)
        ];
    }
}
usort($results, function ($a, $b) {
    return strcmp($a['sort'], $b['sort']);
});
?>

<?php
$idCitizenStmt = $pdo->prepare("SELECT id, user_id, zone_id FROM citizen WHERE town_id = ? AND alive = 1");  // Citoyens de la ville
$bankStmt = $pdo->prepare("SELECT bank_id FROM town WHERE id = ?");  // 2️⃣ Bank ID de la ville
$statusStmt = $pdo->prepare("SELECT citizen_status_id FROM citizen_citizen_status WHERE citizen_id = ?");  // Statuts des citoyens
$buildingStmt = $pdo->prepare("SELECT complete FROM building WHERE town_id=? AND prototype_id=?");  // Vérifier building complete
$csUpgradeStmt = $pdo->prepare("SELECT prototype_id, level FROM citizen_home_upgrade WHERE home_id = ? AND prototype_id=5");  // Vérifier CS upgrade
$clotureUpgradeStmt = $pdo->prepare("SELECT prototype_id, level FROM citizen_home_upgrade WHERE home_id = ? AND prototype_id=7");  // Vérifier Clôture upgrade
$renfortUpgradeStmt = $pdo->prepare("SELECT prototype_id, level FROM citizen_home_upgrade WHERE home_id = ? AND prototype_id=9");  // Vérifier Renfort upgrade
$itemStmt = $pdo->prepare("SELECT prototype_id FROM item WHERE inventory_id=? AND prototype_id=?");  // Vérifier item dans la bank
$nameStmt = $pdo->prepare("SELECT name FROM user WHERE id = ?");  // Récupérer le pseudo
$logStmt = $pdo->prepare("SELECT type, timestamp FROM action_event_log WHERE citizen_id = ? ORDER BY timestamp DESC LIMIT 5");
$actionStmt = $pdo->prepare("SELECT citizen_id, type, count FROM action_counter WHERE last >= CURDATE() AND last < CURDATE() + INTERVAL 1 DAY");
$cuisineStmt = $pdo->prepare("SELECT level FROM citizen_home_upgrade WHERE home_id = ? AND prototype_id = 3");
$laboStmt = $pdo->prepare("SELECT level FROM citizen_home_upgrade WHERE home_id = ? AND prototype_id = 2");
$campingChance = $pdo->prepare("SELECT camping_chance FROM citizen WHERE town_id = ? AND id = ? AND alive = 1");
$goule = $pdo->prepare("SELECT citizen_id FROM citizen_citizen_role WHERE citizen_role_id = 4");
$voracite = $pdo->prepare("SELECT ghul_hunger FROM citizen WHERE id=?;");

$idCitizenStmt->execute([$town_id]);
$citizens = $idCitizenStmt->fetchAll(PDO::FETCH_ASSOC);
$bankStmt->execute([$town_id]);
$bank_id = $bankStmt->fetchColumn();
$actionStmt->execute();
$actionsToday = [];
foreach ($actionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {$actionsToday[(int)$row['citizen_id']][(int)$row['type']] = (int)$row['count'];}
$lists = [];

// Cantine centrale (prototype_id = 48)
$buildingStmt->execute([$town_id, 48]);
$cantineCentrale = ($buildingStmt->fetchColumn() == 1);
// Labo central (prototype_id = 49)
$buildingStmt->execute([$town_id, 49]);
$laboCentral = ($buildingStmt->fetchColumn() == 1);
// Les goules de la ville
$goule->execute();
$listeGoules = $goule->fetchAll(PDO::FETCH_COLUMN);

foreach ($citizens as $c) {
    $citizen_id = $c['id'];
    $citizen_user_id = $c['user_id'];

    // pseudo réel
    $nameStmt->execute([$c['user_id']]);
    $userRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
    $username = $userRow ? $userRow['name'] : '???';

    // statuts
    $statusStmt->execute([$citizen_id]);
    $statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN, 0); // array of citizen_status_id
    $statusesInt = array_map('intval', $statuses);

    // CS
    $csUpgradeStmt->execute([$citizen_id]);
    $homeUpgrade = $csUpgradeStmt->fetch(PDO::FETCH_ASSOC);
    
    // -----------------------------
    // 1️⃣ Déshydratés (status_id=12)
    if (in_array(12, $statusesInt)) {
        $suffix = '';
        if (array_intersect([6,7,8], $statusesInt)) {
            $suffix = ' (VLM)';
        }
        $lists['Deshydrates'][] = $username . $suffix;
    }

    // 2️⃣ Infectés (status_id=13)
    if (in_array(15, $statusesInt)) {
        $suffix = '';
        if (array_intersect([6,7,8], $statusesInt)) $suffix = ' (VLM)';
        if (in_array(5, $statusesInt)) $suffix = $suffix ? $suffix . ' (PARA)' : ' (PARA)';
        $lists['INFECTES !!'][] = $username . $suffix;
    }

    if ($currentDay <40) {
        // 3️⃣ Bassin (status_id !=81) → affiché uniquement si building complete=1
        $buildingStmt->execute([$town_id, 113]);
        $complete = $buildingStmt->fetchColumn();
        if ($complete == 1 && !in_array(81, $statusesInt)) {
            $lists['BASSIN !!'][] = $username;
        }
    }

    // 4️⃣ Bu (status_id !=2)
    if (!in_array(2, $statusesInt)) {
        $lists['Pas Bu'][] = $username;
    }

    // 5️⃣ Mangé (status_id !=3)
    if (!in_array(3, $statusesInt)) {
        $lists['Pas Mange'][] = $username;
    }

    // 6️⃣ Home upgrade prototype_id=5
    if ($homeUpgrade && !in_array(55, $statusesInt)) {
        $lists['Sieste'][] = $username;
    }

    // 7️⃣ Dés (prototype_id=247 dans bank)
    $itemStmt->execute([$bank_id, 247]);
    $hasItem247 = $itemStmt->fetchColumn();
    if ($hasItem247 && !in_array(31, $statusesInt)) {
        $lists['Dés'][] = $username;
    }

    // 8️⃣ Cartes 237
    $itemStmt->execute([$bank_id, 237]);
    $hasItem237 = $itemStmt->fetchColumn();
    if ($hasItem237 && !in_array(32, $statusesInt)) {
        $lists['Cartes'][] = $username;
    }

    // 9️⃣ Ballon 368_369 (cassé / pas cassé)
    $itemStmt->execute([$bank_id, 368]);
    $hasItem368 = $itemStmt->fetchColumn();
    $itemStmt->execute([$bank_id, 369]);
    $hasItem369 = $itemStmt->fetchColumn();
    if (($hasItem368 || $hasItem369) && !in_array(91, $statusesInt)) {
        $lists['Ballon'][] = $username;
    }

    // 1️⃣0️⃣ Drapeau 367
    $itemStmt->execute([$bank_id, 367]);
    $hasItem367 = $itemStmt->fetchColumn();
    if ($hasItem367 && !in_array(92, $statusesInt)) {
        $lists['DRAPEAU !!'][] = $username;
    }

    // 1️⃣1️⃣ Sieste
    if (!$homeUpgrade) {
        $lists['CS à faire'][] = $username . ' (0)';
    } elseif ((int)$homeUpgrade['level'] === 1) {
        $lists['CS à faire'][] = $username . ' (1)';
    }

    // -----------------------------
    // 1️⃣2️⃣ 1️⃣3️⃣ 1️⃣4️⃣ : AH restantes

    $heroicStmt->execute([$citizen_id]);
    $heroics = $heroicStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $heroicsInt = array_map('intval', $heroics);

    // RDH restant (prototype_id = 1)
    if (in_array(1, $heroicsInt)) {
        $lists['Rdh'][] = $username;
    }

    // SS restant (prototype_id = 4)
    if (in_array(4, $heroicsInt)) {
        $lists['Ss'][] = $username;
    }

    // Sauvetage restant (prototype_id = 10)
    if (in_array(10, $heroicsInt)) {
        $lists['Sauv'][] = $username;
    }
    // 1️⃣5️⃣ Repaire (status_id !=80) → affiché uniquement si building complete=1
    $buildingStmt->execute([$town_id,151]);
    $complete = $buildingStmt->fetchColumn();
    if ($complete == 1 && !in_array(80, $statusesInt)) {
        $lists['Repaire'][] = $username;
    }
 
   // 1️⃣6️⃣ Pas soif (status_id=11)
    if ($currentDay <40) {
        if (!in_array(11, $statusesInt)) {
            $lists['Pas soif'][] = $username;
        }
    }
   // 1️⃣7️⃣ Terreur (status_id=10)
    if (in_array(10, $statusesInt)) {
        $lists['Terreur'][] = $username;
        // 1️⃣8️⃣ Teddy (prototype_id=46 dans bank)
        $itemStmt->execute([$bank_id, 46]);
        $hasItem247 = $itemStmt->fetchColumn();
        if ($hasItem247 && !in_array(35, $statusesInt)) {
            $lists['Teddy'][] = $username;
        }
    }

   // 1️⃣9️⃣ Citoyens bannis
    if ($currentDay <40) {
        $citizenInfoStmt->execute([$citizen_user_id, $town_id]);
        $info = $citizenInfoStmt->fetch(PDO::FETCH_ASSOC);
        if ((int)$info['banished'] == 1) {
            $lists['Bannis'][] = $username;
        }
        else {
            // 2️⃣0️⃣ Anti-abus
            if (is_null($c['zone_id'])) {
                $logStmt->execute([$citizen_id]);
                $actions = $logStmt->fetchAll(PDO::FETCH_ASSOC);

                $now = new DateTime();
                $limit = (clone $now)->modify('-15 minutes');
                $dates = array_map(fn($a) => new DateTime($a['timestamp']),$actions);          
                $mostRecentDate = $dates[0];
                $mostRecentType = (int)$actions[0]['type'];
                $oldestDate = $dates[count($dates) - 1];

                if ($mostRecentType === 2 && $mostRecentDate > $limit) {
                    $nextAvailable = (clone $mostRecentDate)->modify('+15 minutes');
                    $lists['Anti-abus'][] = $nextAvailable->format('H:i:s') . " " . $username;
                }
                else {

                    // 🟢 CAS 1 : la plus récente a plus de 15 minutes
                    if ($mostRecentDate < $limit) {
                        $lists['Anti-abus'][] = "." . $username . " : 5";
                    }

                    // 🟡 CAS 2 : toutes les actions sont dans les 15 minutes
                    elseif ($oldestDate > $limit) {
                        $nextAvailable = (clone $mostRecentDate)->modify('+15 minutes');
                        $lists['Anti-abus'][] = $nextAvailable->format('H:i:s') . " " . $username;
                    }

                    // 🔵 CAS 3 : cas intermédiaire
                    else {
                        $remaining = 0;
                        foreach ($dates as $d) {
                            if ($d < $limit) {
                                $remaining++;
                            }
                        }
                        $lists['Anti-abus'][] = "." . $username . " : " . $remaining;
                    }
                }
            }
        }
    }
    if ($currentDay > 14) { // (J15+)
        // 2️⃣1️⃣ Clôture
        $clotureUpgradeStmt->execute([$citizen_id]);
        $stmt = $clotureUpgradeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stmt) {
            $lists['Cloture'][] = $username;
        }
        // 2️⃣2️⃣ Au moins Renfort 1
        $renfortUpgradeStmt->execute([$citizen_id]);
        $stmt = $renfortUpgradeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stmt) {
            $lists['Renfort0'][] = $username;
        }
    }

    // 2️⃣3️⃣ Pas encore cuisiné ajd 
    $cuisineStmt->execute([$citizen_id]);
    $cuisine = $cuisineStmt->fetch(PDO::FETCH_ASSOC);

    if ($cuisine) {
        $level = (int)$cuisine['level'];
        switch ($level) {
            case 1:
            case 2: $cuisineMax = 1; break;
            case 3: $cuisineMax = 2; break;
            case 4: $cuisineMax = 3; break;
            default: $cuisineMax = 0;
        }
        if ($cantineCentrale) {$cuisineMax += 3;}
        $cuisineAjd = $actionsToday[$citizen_id][2] ?? 0;
        if ($cuisineAjd !== $cuisineMax) {$lists['Cuisine'][] = $level . " : " . $username . " (" . $cuisineAjd . "/" . $cuisineMax . ")";}
    }

    // 2️⃣4️⃣ Pas encore labo ajd 
    $laboStmt->execute([$citizen_id]);
    $labo = $laboStmt->fetch(PDO::FETCH_ASSOC);

    if ($labo) {
        $level = (int)$labo['level'];
        if ($level >= 1 && $level <= 3) {$laboMax = 1;}
        elseif ($level == 4) {$laboMax = 4;}
        else {$laboMax = 0;}
        if ($laboCentral) {$laboMax += 5;}
        $laboAjd = $actionsToday[$citizen_id][3] ?? 0;
        if ($laboAjd !== $laboMax) {$lists['Labo'][] = $level . " : " . $username . " (" . $laboAjd . "/" . $laboMax . ")";}
    }

    if ($currentDay >= 40) {
        // 2️⃣5️⃣ 2️⃣6️⃣ Joueurs pas cachés / cachés + %  
        $campingChance->execute([$town_id, $citizen_id]);
        $campingChancePerCent = $campingChance->fetchColumn();
        $campingChancePerCent *= 100;
        if ($campingChancePerCent == 0) {
            $lists['PAS CACHE !!'][] = $username;
        }
        else {
            $lists['% camping'][] = $username . ' ' . $campingChancePerCent . '%';
        }
    }
    
    // 2️⃣7️⃣ Dépendant mais pas drogué (status_id)
    if (in_array(14, $statusesInt) && !in_array(13, $statusesInt)) {
        $lists['DROGUE !!!'][] = $username;
    }

    // 2️⃣8️⃣ Blessé (status_id)
    if (
        in_array(18, $statusesInt) ||
        in_array(19, $statusesInt) ||
        in_array(20, $statusesInt) ||
        in_array(21, $statusesInt) ||
        in_array(22, $statusesInt) ||
        in_array(23, $statusesInt)
    ) {
        $lists['BLESSE !!!'][] = $username;
    }
    // 2️⃣9️⃣ Goules avec voracité
    if (in_array($citizen_id, $listeGoules)) {
        $voracite->execute([$citizen_id]);
        $voracitepourcent = $voracite->fetchColumn();
        if ($voracitepourcent > 40) {
            $lists['Goules'][] = "!!!! " . $voracitepourcent . " : " . $username;
        }
        else {
            $lists['Goules'][] = $voracitepourcent . " : " . $username;
        }
    }

}





foreach ($lists as $key => &$list) {
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
}
unset($list); // sécurité
?>

<div class="lists-container">

    <?php if (!empty($results)): ?>
        <div class="list">
            <strong><u>Fouille</u></strong><br>
            <?php foreach ($results as $r): ?>
                <span style="color:<?= $r['exhausted'] ? 'red' : 'inherit' ?>;">
                    <?= htmlspecialchars($r['user']) ?>: <?= htmlspecialchars($r['time']) ?>
                </span><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    foreach ($lists as $title => $list) {
        if (!empty($list)) {
            echo '<div class="list">';
            echo '<strong><u>' . htmlspecialchars($title) . '</u></strong><br>';
            foreach ($list as $name) {
                echo htmlspecialchars($name) . '<br>';
            }
            echo '</div>';
        }
    }
    ?>

</div>

</body>
</html>
