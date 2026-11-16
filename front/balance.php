<?php

use GlpiPlugin\Contractlifecycle\Balance;
use GlpiPlugin\Contractlifecycle\Ledger;
use GlpiPlugin\Contractlifecycle\Menu;

Session::checkRight(Ledger::RIGHT, READ);

Html::header(__('Saldos de contratos', 'contractlifecycle'), '', 'management', Menu::class, 'balance');
Balance::show(empty($_GET['all']));
Html::footer();
