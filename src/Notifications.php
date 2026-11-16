<?php

namespace GlpiPlugin\Contractlifecycle;

use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/** Instala/remove o modelo e a notificação padrão do aviso de renovação. */
class Notifications
{
    public static function install(): void
    {
        global $DB;
        $itemtype = ContractData::class;

        $template = new NotificationTemplate();
        if ($template->getFromDBByCrit(['itemtype' => $itemtype])) {
            return; // já instalado (o usuário pode ter personalizado)
        }
        $templates_id = $template->add([
            'name'     => __('Ciclo de vida: aviso de renovação', 'contractlifecycle'),
            'itemtype' => $itemtype,
            'comment'  => '',
        ]);
        (new NotificationTemplateTranslation())->add([
            'notificationtemplates_id' => $templates_id,
            'language'     => '',
            'subject'      => '##contract.name## — renovação automática em ##contract.daysleft## dias',
            'content_text' => "Contrato: ##contract.name## (##contract.type##)\nEntidade: ##contract.entity##\n"
                . "Fim da vigência: ##contract.enddate## (##contract.daysleft## dias)\n\n"
                . "O contrato será renovado automaticamente. Para cancelar a renovação, entre em contato antes da data final.\n\n"
                . "##contract.url##",
            'content_html' => '<p>Contrato: <strong>##contract.name##</strong> (##contract.type##)<br>Entidade: ##contract.entity##<br>'
                . 'Fim da vigência: <strong>##contract.enddate##</strong> (##contract.daysleft## dias)</p>'
                . '<p>O contrato será renovado automaticamente. Para cancelar a renovação, entre em contato antes da data final.</p>'
                . '<p><a href="##contract.url##">##contract.url##</a></p>',
        ]);

        $notification = new Notification();
        $notifications_id = $notification->add([
            'name'         => __('Ciclo de vida: aviso de renovação', 'contractlifecycle'),
            'entities_id'  => 0,
            'is_recursive' => 1,
            'is_active'    => 1,
            'itemtype'     => $itemtype,
            'event'        => NotificationTargetContractData::EVENT_RENEWAL_NOTICE,
        ]);
        (new Notification_NotificationTemplate())->add([
            'notifications_id'         => $notifications_id,
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $templates_id,
        ]);
        foreach ([NotificationTargetContractData::TARGET_FOCAL_CONTRACT, NotificationTargetContractData::TARGET_NOTICE_GROUP] as $target) {
            $DB->insert(NotificationTarget::getTable(), [
                'notifications_id' => $notifications_id,
                'type'             => Notification::USER_TYPE,
                'items_id'         => $target,
            ]);
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        $itemtype = ContractData::class;
        $notification = new Notification();
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $notification->delete(['id' => $row['id']], true);
        }
        $template = new NotificationTemplate();
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            $template->delete(['id' => $row['id']], true);
        }
    }
}
