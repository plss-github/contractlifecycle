<?php

use GlpiPlugin\Contractlifecycle\Focal;

Session::checkRight('entity', UPDATE);
Focal::handlePost($_POST);
Html::back();
