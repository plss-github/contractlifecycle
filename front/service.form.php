<?php

use GlpiPlugin\Contractlifecycle\Service;

Session::checkRight('contract', UPDATE);
Service::handlePost($_POST);
Html::back();
