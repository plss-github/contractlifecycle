<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonGLPI;
use Session;

/** Entrada "Ciclo de contratos" no menu Gerência. */
class Menu extends CommonGLPI
{
    public static $rightname = Ledger::RIGHT;

    public static function getTypeName($nb = 0)
    {
        return __('Ciclo de contratos', 'contractlifecycle');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Ledger::RIGHT, READ) || Session::haveRight(Settings::RIGHT, READ);
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $menu = [
            'title' => self::getTypeName(),
            'page'  => Helpers::path('front/balance.php'),
            'icon'  => self::getIcon(),
            'options' => [],
        ];
        if (Session::haveRight(Ledger::RIGHT, READ)) {
            $menu['options']['balance'] = [
                'title' => __('Saldos', 'contractlifecycle'),
                'page'  => Helpers::path('front/balance.php'),
                'icon'  => 'ti ti-scale',
            ];
        }
        if (Session::haveRight(Settings::RIGHT, READ)) {
            $menu['options']['rate'] = [
                'title' => Rate::getTypeName(2),
                'page'  => Rate::getSearchURL(false),
                'icon'  => Rate::getIcon(),
                'links' => [
                    'search' => Rate::getSearchURL(false),
                    'add'    => Rate::getFormURL(false),
                ],
            ];
            $menu['options']['config'] = [
                'title' => __('Configuração geral', 'contractlifecycle'),
                'page'  => Helpers::path('front/config.php'),
                'icon'  => 'ti ti-settings',
            ];
            $menu['links'] = [
                'config' => Helpers::path('front/config.php'),
            ];
        }
        return $menu;
    }
}
