<?php

$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_banque.php?id=xx";
    exit;
}

$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Récupération de l'inventaire de la ville
$stmt = $pdo->prepare("SELECT bank_id FROM town WHERE id = ?");
$stmt->execute([$town_id]);
$bank_id = $stmt->fetchColumn();
if (!$bank_id) {
    echo "Pas de banque pour cette ville.";
    exit;
}

// Récupération des objets
$stmt = $pdo->prepare("
    SELECT id, prototype_id, broken, count
    FROM item
    WHERE inventory_id = ?
    ORDER BY id ASC
");
$stmt->execute([$bank_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des prototypes
$protoStmt = $pdo->query("SELECT id, name, category_id FROM item_prototype");
$prototypes = [];
while ($row = $protoStmt->fetch(PDO::FETCH_ASSOC)) {
    $prototypes[$row['id']] = [
        'name' => $row['name'],
        'category_id' => $row['category_id']
    ];
}

// Chargement des images
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

// Organisation des items par catégorie
$itemsByCategory = [];
foreach ($items as $item) {
    $proto = $prototypes[$item['prototype_id']] ?? null;
    if (!$proto) continue;

    $cat_id = $proto['category_id'] ?: 8; // Divers par défaut

    $itemsByCategory[$cat_id][] = [
        'prototype_id' => (int)$item['prototype_id'],
        'count'        => (int)$item['count'],
        'name'         => $proto['name'],
        'broken'       => !empty($item['broken'])
    ];
}

$categoryLabels = [
    1 => 'Ressources',
    2 => 'Aménagements',
    3 => 'Armurerie',
    4 => 'Conteneurs et boîtes',
    5 => 'Défense',
    6 => 'Pharmacie',
    7 => 'Provisions',
    8 => 'Divers',
];

$totalObjets = 0;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Banque : <?= htmlspecialchars($town_id) ?></title>
<style>
body { background: rgb(172,106,57); }
.center-box {position: fixed;top: 60px;left: 50%;transform: translateX(-50%);width: 50%;background: rgb(100,100,100);border: 2px solid #ccc;border-radius: 8px;padding: 10px;box-shadow: 0 4px 12px rgba(0,0,0,0.3);}
.recap-objets-category-title {font-weight: bold;font-size: 16px;padding-top: 5px;}
.recap-objets-list {display: flex;flex-wrap: wrap;gap: 4px;}
.recap-objets-item {display: flex;align-items: center;font-size: 10px;border: 2px solid #ccc;background: rgb(197,197,197);border-radius: 4px;padding: 2px 4px;gap: 4px;}
.recap-objets-item img {width: 18px;height: 18px;object-fit: contain;}
.recap-objets-count {font-weight: bold;font-size: 15px;}
</style>
</head>

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

<body style="margin:0; padding-top:20px;">

<div class="center-box">
    <?php foreach ($categoryLabels as $catId => $catLabel): ?>
        <?php if (empty($itemsByCategory[$catId])) continue; ?>

        <?php
        // Total par catégorie
        $totalCategoryCount = array_sum(
            array_column($itemsByCategory[$catId], 'count')
        );
        $totalObjets += $totalCategoryCount;

        // Groupement par prototype_id
        $groupedItems = [];
        foreach ($itemsByCategory[$catId] as $item) {
            $protoId = $item['prototype_id'];

            if (!isset($groupedItems[$protoId])) {
                $groupedItems[$protoId] = [
                    'name' => $item['name'],
                    'count_ok' => 0,
                    'count_broken' => 0,
                    'img' => getItemImagePathFast($item['name'], $itemImages),
                ];
            }

            if ($item['broken']) {
                $groupedItems[$protoId]['count_broken'] += $item['count'];
            } else {
                $groupedItems[$protoId]['count_ok'] += $item['count'];
            }
        }

        // 🔥 TRI PAR prototype_id
        ksort($groupedItems, SORT_NUMERIC);
        ?>

        <div class="recap-objets-category">
            <div class="recap-objets-category-title" style="padding-bottom:6px;">
                <u><?= htmlspecialchars($catLabel) ?> (<?= $totalCategoryCount ?>)</u>
            </div>

            <div class="recap-objets-list">
                <?php foreach ($groupedItems as $protoId => $data): ?>
                    <?php if (!$data['img']) continue; ?>
                    <div class="recap-objets-item" title="ID objet : <?= $protoId ?>">
                        <div class="recap-objets-count">
                            <?= $data['count_ok'] ?>
                            <?php if ($data['count_broken'] > 0): ?>
                                (+<?= $data['count_broken'] ?>)
                            <?php endif; ?>
                        </div>
                        <img src="<?= htmlspecialchars($data['img']) ?>"
                            alt="<?= htmlspecialchars($data['name']) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <br>
        </div>

    <?php endforeach; ?>
    <strong><?php echo "TOTAL OBJETS : " . $totalObjets; ?></strong>
</div>

</body>
</html>
