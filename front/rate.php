<?php

use GlpiPlugin\Contractlifecycle\Menu;
use GlpiPlugin\Contractlifecycle\Rate;
use GlpiPlugin\Contractlifecycle\Settings;

Session::checkRight(Settings::RIGHT, READ);

Html::header(Rate::getTypeName(2), '', 'management', Menu::class, 'rate');
Search::show(Rate::class);
Html::footer();
