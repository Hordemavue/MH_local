<?php

$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_plans.php?id=xx";
    exit;
}

$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function tr(string $key): string {
    static $tr = null;
    if ($tr === null) {
        $tr = require __DIR__ . '/translations_buildings_fr.php';
    }
    return $tr[$key] ?? $key;
}

$stmt = $pdo->query("SELECT id, label, blueprint FROM building_prototype");
$prototypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT prototype_id FROM building WHERE town_id=?");
$stmt->execute([$town_id]);
$built = $stmt->fetchAll(PDO::FETCH_COLUMN);

$categories = [
    1 => ['label' => 'Verts', 'bg_light' => 'rgb(182,215,168)', 'bg_dark' => 'rgb(106,168,79)'],
    2 => ['label' => 'Jaunes', 'bg_light' => 'rgb(255,229,153)', 'bg_dark' => 'rgb(241,194,50)'],
    3 => ['label' => 'Bleus', 'bg_light' => 'rgb(164,194,244)', 'bg_dark' => 'rgb(109,158,235)'],
    4 => ['label' => 'Mauves', 'bg_light' => 'rgb(180,167,214)', 'bg_dark' => 'rgb(142,124,195)'],
];

$by_category = [];
foreach ($prototypes as $p) {
    $by_category[$p['blueprint']][] = $p;
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Plans : <?= htmlspecialchars($town_id) ?></title>
<style>
body { background-color: rgb(204,204,204); font-family: sans-serif; font-size: 12px; color: black; padding: 20px;padding-top: 0px;}
.container { display: flex; gap: 20px; align-items: flex-start;}
.category { display: inline-block; border: 1px solid black; flex-shrink: 0; font-size: 12px;}
.category-header { padding: 5px; text-align: center; font-weight: bold; border-bottom: 1px solid black; }
.building-row { display: flex; }
.building-name, .building-status { padding: 3px 5px; border-bottom: 1px solid black; color: black; white-space: nowrap;}
.building-name { width: 160px; flex: none; border-right: 1px solid black; }
.building-status { width: 40px; flex: none; text-align: center; border-right: none;}
.building-row:last-child .building-name, .building-row:last-child .building-status {border-bottom: none;}
</style>
</head>
<body style="padding-top:10px;">
<?php
// Création du collator français pour trier correctement les accents
$coll = collator_create('fr_FR');
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

<!-- Colonnes de plans -->
<div class="container" style="margin-top:20px; display:flex; gap:20px; align-items:flex-start;">

    <?php foreach ($categories as $id => $cat): ?>
        <?php
        $total = count($by_category[$id] ?? []);
        $found = 0;
        if (!empty($by_category[$id])) {
            foreach ($by_category[$id] as $b) {
                if (in_array($b['id'], $built)) $found++;
            }
        }
        ?>
        <div class="category">
            <!-- Intitulé -->
            <div class="category-header" style="background-color: <?= $cat['bg_light'] ?>; font-weight: bold;">
                <?= $cat['label'] ?> : <?= $found ?> / <?= $total ?>
            </div>

            <?php if (!empty($by_category[$id])): ?>
                <?php
                usort($by_category[$id], function($a, $b) use ($coll) {
                    return collator_compare($coll, tr($a['label']), tr($b['label']));
                });
                ?>
                <?php foreach ($by_category[$id] as $b): ?>
                    <?php
                    $is_found = in_array($b['id'], $built);
                    $status_text = $is_found ? 'Oui' : 'Non';
                    $bg_name = $cat['bg_dark'];
                    $bg_status = $is_found ? $cat['bg_light'] : 'rgb(183,183,183)';
                    ?>
                    <div class="building-row" style="display:flex;">
                        <div class="building-name" style="background-color: <?= $bg_name ?>; border-bottom: <?= $border_bottom_name ?>; ...">
                            <?= tr($b['label']) ?>
                        </div>
                        <div class="building-status" style="background-color: <?= $bg_status ?>; border-bottom: <?= $border_bottom_status ?>; ...">
                            <?= $status_text ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Cinquième colonne vide pour garder l'espace -->
    <div class="category" style="display: inline-block;"></div>

</div>
</body>

</html>
