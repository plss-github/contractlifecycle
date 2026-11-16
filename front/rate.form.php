<?php

use GlpiPlugin\Contractlifecycle\Menu;
use GlpiPlugin\Contractlifecycle\Rate;
use GlpiPlugin\Contractlifecycle\Settings;

Session::checkRight(Settings::RIGHT, READ);

$rate = new Rate();

if (isset($_POST['add'])) {
    $rate->check(-1, CREATE, $_POST);
    $id = $rate->add($_POST);
    Html::redirect($id ? $rate->getFormURLWithID($id) : Rate::getSearchURL());
} elseif (isset($_POST['update'])) {
    $rate->check((int) $_POST['id'], UPDATE);
    $rate->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $rate->check((int) $_POST['id'], PURGE);
    $rate->delete($_POST, true);
    $rate->redirectToList();
}

Html::header(Rate::getTypeName(1), '', 'management', Menu::class, 'rate');
$rate->display(['id' => (int) ($_GET['id'] ?? 0)]);
Html::footer();
