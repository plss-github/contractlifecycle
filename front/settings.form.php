<?php

use GlpiPlugin\Contractlifecycle\Settings;

Session::checkRight(Settings::RIGHT, UPDATE);

if (isset($_POST['save'])) {
    Settings::handlePost($_POST);
}
Html::back();
