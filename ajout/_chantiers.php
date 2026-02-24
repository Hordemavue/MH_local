<?php

$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_chantiers.php?id=xx";
    exit;
}

// ================= CONFIG BDD =================
$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ================= BUILDING_PROTOTYPE (ARBRE) =================

$sql = "
    SELECT id, order_by, parent_id, label
    FROM building_prototype
    ORDER BY parent_id IS NOT NULL, parent_id, id
";
$stmt = $pdo->query($sql);
$buildingPrototypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================= BUILDING (VILLE + PA) =================

$sql = "
    SELECT 
        b.prototype_id,
        b.complete,
        b.ap AS ap_done,
        bp.ap AS ap_total
    FROM building b
    JOIN building_prototype bp ON bp.id = b.prototype_id
    WHERE b.town_id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$town_id]);
$townBuildings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================= INDEX PAR PROTOTYPE =================

$townBuildingsByPrototype = [];
foreach ($townBuildings as $b) {
    $townBuildingsByPrototype[$b['prototype_id']] = $b;
}

// ================= FONCTION TRANSLATE =================

function tr(string $key): string {
    static $tr = null;
    if ($tr === null) {
        $tr = require __DIR__ . '/translations_buildings_fr.php';
    }
    return $tr[$key] ?? $key;
}

// ================= TREE DES CHANTIERS =================

$tree = [];

foreach ($buildingPrototypes as $b) {
    $parentId = $b['parent_id'] ?? 0;
    $tree[$parentId][] = $b;
}

// ================= TRI DES NIVEAUX =================

foreach ($tree as &$children) {
    usort($children, function ($a, $b) {
        $cmp = ($a['order_by'] ?? 0) <=> ($b['order_by'] ?? 0);
        return $cmp !== 0 ? $cmp : ($a['id'] <=> $b['id']);
    });
}
unset($children);

// ================= CHANTIERS INTERDITS =================

$barredBuildingIds = [
    6, 32, 34, 38, 44, 45, 47, 53, 61, 76,
    79, 80, 82, 84, 87, 90, 91, 92, 93,
    96, 109, 110, 114, 115, 116, 117,
    118, 119, 130, 133, 145, 146
];

// ================= RENDER RECURSIF =================

function renderChildren(
    array $tree,
    array $townBuildingsByPrototype,
    array $barredBuildingIds,
    int $parentId = 0,
    int $level = 0
) {
    if (!isset($tree[$parentId])) {
        return;
    }

    foreach ($tree[$parentId] as $child) {

        $id = (int)$child['id'];

        if (
            in_array($id, $barredBuildingIds, true) ||
            !isset($townBuildingsByPrototype[$id])
        ) {
            continue;
        }

        $isComplete = (int)$townBuildingsByPrototype[$id]['complete'];
        $prefix = str_repeat('- ', $level);

        if ($isComplete === 0) {

            $apDone  = (int)$townBuildingsByPrototype[$id]['ap_done'];
            $apTotal = (int)$townBuildingsByPrototype[$id]['ap_total'];
            $apLeft  = max(0, $apTotal - $apDone);

            echo '<div>';
            echo $prefix . htmlspecialchars(tr($child['label']));
            echo ' <span style="color:#666;font-size:12px;">';
            echo '(' . $apLeft . ' / ' . $apTotal . ' PA)';
            echo '</span>';
            echo '</div>';
        }

        renderChildren(
            $tree,
            $townBuildingsByPrototype,
            $barredBuildingIds,
            $id,
            $level + 1
        );
    }
}


// =============================== HTML =============================

?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Chantiers : <?= htmlspecialchars($town_id) ?></title>
<style>
.chantiers {display: flex;gap: 20px;}
.chantier-col {border: 1px solid #ccc;padding: 10px;min-width: 220px;}
.chantier-parent {font-weight: bold;margin-bottom: 8px;}
</style>
</head>
<body style="margin-top:0px;margin-bottom:0px;padding-top:20px;">

<div style="position: relative; padding: 8px 12px; display: flex; align-items: center; min-height: unset;">

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

<div class="chantiers" style="margin-top:20px;">
<?php foreach ($tree[0] ?? [] as $parent): ?>

    <?php
    $parentId = (int)$parent['id'];

    if (
        in_array($parentId, $barredBuildingIds, true) ||
        !isset($townBuildingsByPrototype[$parentId])
    ) {
        continue;
    }

    $parentComplete = (int)$townBuildingsByPrototype[$parentId]['complete'];
    ?>

    <div class="chantier-col">

        <?php if ($parentComplete === 0): ?>

            <?php
                $apDone  = (int)$townBuildingsByPrototype[$parentId]['ap_done'];
                $apTotal = (int)$townBuildingsByPrototype[$parentId]['ap_total'];
                $apLeft  = max(0, $apTotal - $apDone);
            ?>

            <div class="chantier-parent">
                <?= htmlspecialchars(tr($parent['label'])) ?>
                <span style="font-size:12px;color:#555;">
                    (<?= $apLeft ?> / <?= $apTotal ?> PA)
                </span>
            </div>

        <?php endif; ?>

        <?php
        renderChildren(
            $tree,
            $townBuildingsByPrototype,
            $barredBuildingIds,
            $parentId,
            1
        );
        ?>
    </div>

<?php endforeach; ?>
</div>


</body>
</html>


