<?php

use GlpiPlugin\Contractlifecycle\Install;

function plugin_contractlifecycle_install()
{
    return Install::install(new Migration(PLUGIN_CONTRACTLIFECYCLE_VERSION));
}

function plugin_contractlifecycle_uninstall()
{
    return Install::uninstall();
}
