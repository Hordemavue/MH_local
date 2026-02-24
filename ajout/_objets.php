<?php

// LISTE NON-EXHAUSTIVE
$importantItems = [141,74,72,70,67,340,337,330,329,246];


$town_id = $_GET['id'] ?? null;
if (!$town_id) {
    echo "URL attendue : http://localhost:8081/_objets.php?id=xx";
    exit;
}

$filter15 = isset($_GET['max15']) && $_GET['max15'] === '1';

$pdo = new PDO(
    'mysql:host=mariadb;dbname=myhordes;charset=utf8',
    'root',
    'myh0rd3s',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sqlZones = $pdo->prepare("SELECT floor_id, x, y FROM zone WHERE town_id = ?");
$sqlZones->execute([$town_id]);
$zones = $sqlZones->fetchAll(PDO::FETCH_ASSOC);

$zonesByFloor = [];
foreach ($zones as $z) {
    $zonesByFloor[$z['floor_id']] = $z;
}

$sqlItems = $pdo->query("SELECT inventory_id, prototype_id FROM item");
$items = $sqlItems->fetchAll(PDO::FETCH_ASSOC);

function getDirection($x,$y){
    if (abs($x)+abs($y)<=1) return 'CENTRE';
    if ($y>=0 && abs($x)<=floor($y/2)) return 'N';
    if ($y<=0 && abs($x)<=floor(-$y/2)) return 'S';
    if ($x<=0 && abs($y)<=floor(-$x/2)) return 'O';
    if ($x>=0 && abs($y)<=floor($x/2)) return 'E';
    if ($x<0 && $y>0) return 'NO';
    if ($x>0 && $y>0) return 'NE';
    if ($x<0 && $y<0) return 'SO';
    if ($x>0 && $y<0) return 'SE';
    return 'INCONNU';
}

function getPA($x,$y){
    return (abs($x)+abs($y))*2-3;
}

$result = [];
$totalPAAll = 0;
$totalByDir = [];

foreach ($items as $item) {

    if (!in_array($item['prototype_id'],$importantItems)) continue;
    if (!isset($zonesByFloor[$item['inventory_id']])) continue;

    $z = $zonesByFloor[$item['inventory_id']];
    $pa = getPA($z['x'],$z['y']);
    if ($pa < 0) continue;
    if ($filter15 && $pa > 15) continue;

    $dir = getDirection($z['x'],$z['y']);
    $result[$dir][$pa] = ($result[$dir][$pa] ?? 0) + 1;
    $totalPAAll += $pa;
    $totalByDir[$dir] = ($totalByDir[$dir] ?? 0) + $pa;
}

$order = ['NO','N','NE','E','O','SO','S','SE','CENTRE'];
uksort($result, fn($a,$b)=>array_search($a,$order)<=>array_search($b,$order));
uksort($totalByDir, fn($a,$b)=>array_search($a,$order)<=>array_search($b,$order));

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>OD : <?= htmlspecialchars($town_id) ?></title>

<style>
body { background:#2b2418;color:#e6dcc6;font-family:Arial,Helvetica,sans-serif;margin:0;padding:12px;}
.viewport { width:25vw;margin:0 auto;transform:scale(2);transform-origin:top center;margin-top:10px;}
.header { position:relative;height:20px;margin-bottom:8px;}
.filter { position:absolute;left:0;top:50%;transform:translateY(-50%);font-size:10px;color:#f1d18a;white-space:nowrap;}
.filter input { transform:scale(0.8);vertical-align:middle;}
.total { position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);font-size:13px;color:#f1d18a;white-space:nowrap;}
.wrapper { display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.card { background:#3a2f1f;border:1px solid #5a4a32;padding:8px;}
.card h2 { margin:0 0 6px;font-size:13px;text-align:center;color:#f1d18a;border-bottom:1px solid #6b5a3a;padding-bottom:4px;}
.line { display:flex;justify-content:space-between;font-size:12px;padding:2px 0;border-bottom:1px dotted #5c4b33;}
.line:last-child { border-bottom:none;}
.pa { color:#c9b58a;}
.count { font-weight:bold;color:#ffffff;}
</style>
</head>

<body>

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

<div class="viewport">

<div class="header">
    <form method="get" class="filter">
        <input type="hidden" name="id" value="<?= htmlspecialchars($town_id) ?>">
        <label>
            <input type="checkbox" name="max15" value="1"
                   onchange="this.form.submit()" <?= $filter15 ? 'checked' : '' ?>>
            ≤ 15 PA
        </label>
    </form>

    <div class="total">
        Total PA pour tout ramener : <strong><?= $totalPAAll ?> PA</strong>
    </div>
</div>

<div class="wrapper">
<?php foreach ($result as $dir => $pas): ?>
    <div class="card">
        <h2><?= $dir ?> (<?= $totalByDir[$dir] ?? 0 ?> PA)</h2>
        <?php krsort($pas); foreach ($pas as $pa => $count): ?>
            <div class="line">
                <span class="pa"><?= $pa ?> PA</span>
                <span class="count"><?= $count ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
</div>

</div>

</body>
</html>
