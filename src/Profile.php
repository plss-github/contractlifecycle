<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonGLPI;
use Html;
use Profile as CoreProfile;

/** Aba "Ciclo de contratos" no perfil, com a matriz de direitos do plugin. */
class Profile extends CommonGLPI
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Ciclo de contratos', 'contractlifecycle');
    }

    public static function getRightsDefinition(): array
    {
        return [
            [
                'itemtype' => Settings::class,
                'label'    => __('Configuração e tabela Per-Event', 'contractlifecycle'),
                'field'    => Settings::RIGHT,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => Ledger::class,
                'label'    => __('Extrato e saldos', 'contractlifecycle'),
                'field'    => Ledger::RIGHT,
                'rights'   => [
                    READ   => __('Read'),
                    CREATE => __('Lançar recarga e ajuste', 'contractlifecycle'),
                    UPDATE => __('Re-alocar horas', 'contractlifecycle'),
                ],
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof CoreProfile && $item->getField('interface') === 'central' && CoreProfile::canView()) {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType(), Menu::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CoreProfile) {
            return false;
        }
        $canedit = CoreProfile::canUpdate() && $item->canUpdateItem();
        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(CoreProfile::getFormURL()) . "'>";
        }
        $item->displayRightsChoiceMatrix(self::getRightsDefinition(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);
        if ($canedit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }
}
