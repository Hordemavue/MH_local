<?php

// ================= PARAMÈTRE =================
$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_ruine.php?id=xx";
    exit;
}
?>

<title>Ruine : <?= htmlspecialchars($town_id) ?></title>

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
// ================= CONFIG BDD =================
$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ================= ICÔNES D’OBJETS =================
$itemImages = [];
foreach (glob(__DIR__ . "/build/images/item/item_*.gif") as $file) {
    if (preg_match('/item_(.+?)\.[0-9a-f]+\.gif$/', basename($file), $m)) {
        $itemImages[$m[1]] = str_replace(__DIR__, '', $file);
    }
}

function getItemImagePathFast(string $name, array $map): ?string {
    $key = preg_replace('/_#\d+$/', '', $name);
    return $map[$key] ?? null;
}

// ================= RÉCUPÉRATION DE LA RUINE =================
$stmt = $pdo->prepare("
    SELECT id, discovery_status
    FROM zone
    WHERE explorable_floors > 1
      AND town_id = ?
");
$stmt->execute([$town_id]);
$zone = $stmt->fetch(PDO::FETCH_ASSOC);

// Ruine inexistante ou non découverte
if (!$zone || $zone['discovery_status'] <= 1) {
    exit;
}

$zone_id = (int)$zone['id'];

// ================= CASES DE LA RUINE =================
$stmt = $pdo->prepare("
    SELECT x, y, z, floor_id
    FROM ruin_zone
    WHERE zone_id = ?
    ORDER BY z ASC, y ASC, x ASC
");
$stmt->execute([$zone_id]);
$ruinZones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Regroupement par étage
$floors = [];
foreach ($ruinZones as $rz) {
    $floors[$rz['z']][] = $rz;
}

// ================= PROTOTYPES =================
$stmt = $pdo->query("
    SELECT id, name, category_id
    FROM item_prototype
");
$protoMap = [];
foreach ($stmt as $row) {
    $protoMap[(int)$row['id']] = $row;
}

// ================= OBJETS DE TOUS LES FLOORS =================
$floorIds = array_unique(array_column($ruinZones, 'floor_id'));
if (empty($floorIds)) {
    exit;
}

$in = implode(',', array_fill(0, count($floorIds), '?'));

$stmt = $pdo->prepare("
    SELECT inventory_id, prototype_id, broken
    FROM item
    WHERE inventory_id IN ($in)
");
$stmt->execute($floorIds);

$itemsRaw = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $itemsRaw[$row['inventory_id']][] = $row;
}

// ================= AFFICHAGE =================
foreach ($floors as $z => $cells) {

    echo "<h2>ETAGE " . ($z + 1) . "</h2>";

    foreach ($cells as $cell) {

        $floorId = $cell['floor_id'];

        // ❌ On ignore les cases sans objet
        if (empty($itemsRaw[$floorId])) {
            continue;
        }

        echo "<div style='margin-bottom:6px'>";
        echo "<strong>{$cell['x']} / {$cell['y']}</strong> : ";

        foreach ($itemsRaw[$floorId] as $item) {

            $protoId = (int)$item['prototype_id'];
            $broken  = (int)$item['broken'];

            if (!isset($protoMap[$protoId])) {
                continue;
            }

            $proto = $protoMap[$protoId];
            $name  = $proto['name'];

            $img = getItemImagePathFast($name, $itemImages);
            if (!$img) {
                continue;
            }

            $style = $broken > 0
                ? 'vertical-align:middle;margin:1px;padding:1px;border:1px solid red;box-sizing:content-box;'
                : 'vertical-align:middle;margin:1px;';

            $title = htmlspecialchars($name . ($broken ? ' (cassé)' : ''));

            echo "<img
                    src='{$img}'
                    title='{$title}'
                    alt='{$title}'
                    style='{$style}'
                />";
        }

        echo "</div>";
    }
}

?>

