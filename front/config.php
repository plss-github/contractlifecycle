<?php

use GlpiPlugin\Contractlifecycle\Menu;
use GlpiPlugin\Contractlifecycle\Settings;

Session::checkRight(Settings::RIGHT, READ);

Html::header(__('Configuração geral', 'contractlifecycle'), '', 'management', Menu::class, 'config');
echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-settings me-2"></i>'
    . htmlescape(__('Ciclo de vida de contratos — configuração geral', 'contractlifecycle'))
    . '</h3></div><div class="card-body">';
Settings::showLevelForm(Settings::LEVEL_GLOBAL, 0);
echo '</div></div>';
Html::footer();
