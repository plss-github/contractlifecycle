<?php

use GlpiPlugin\Contractlifecycle\FormAction;
use GlpiPlugin\Contractlifecycle\Settings;

Session::checkRight(Settings::RIGHT, UPDATE);
FormAction::handlePost($_POST);
Html::back();
