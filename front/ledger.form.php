<?php

use GlpiPlugin\Contractlifecycle\Ledger;

Session::checkRight(Ledger::RIGHT, READ);
Ledger::handlePost($_POST);
Html::back();
