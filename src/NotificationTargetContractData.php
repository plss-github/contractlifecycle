<?php

namespace GlpiPlugin\Contractlifecycle;

use Contract;
use ContractType;
use Notification;
use NotificationTarget;

/**
 * Destinatários e marcadores das notificações do ciclo de vida (aviso de renovação).
 * Item que dispara: ContractData; options: contract, settings, days_left, entities_id.
 */
class NotificationTargetContractData extends NotificationTarget
{
    public const TARGET_FOCAL_CONTRACT  = 4101;
    public const TARGET_FOCAL_FINANCIAL = 4102;
    public const TARGET_FOCAL_TECHNICAL = 4103;
    public const TARGET_NOTICE_GROUP    = 4104;

    public const EVENT_RENEWAL_NOTICE = 'renewal_notice';

    private const ROLE_BY_TARGET = [
        self::TARGET_FOCAL_CONTRACT  => 'contract',
        self::TARGET_FOCAL_FINANCIAL => 'financial',
        self::TARGET_FOCAL_TECHNICAL => 'technical',
    ];

    public function getEvents()
    {
        return [self::EVENT_RENEWAL_NOTICE => __('Aviso de renovação automática', 'contractlifecycle')];
    }

    public function addNotificationTargets($entity)
    {
        foreach (self::ROLE_BY_TARGET as $target => $role) {
            $this->addTarget($target, Settings::roleLabels()[$role]);
        }
        $this->addTarget(self::TARGET_NOTICE_GROUP, __('Grupo de avisos das regras do contrato', 'contractlifecycle'));
    }

    public function addSpecificTargets($data, $options)
    {
        if ((int) $data['type'] !== Notification::USER_TYPE) {
            return;
        }
        $target = (int) $data['items_id'];
        $entities_id = (int) ($this->options['entities_id'] ?? $options['entities_id'] ?? 0);
        if (isset(self::ROLE_BY_TARGET[$target])) {
            foreach (Focal::usersFor($entities_id, [self::ROLE_BY_TARGET[$target]]) as $users_id) {
                $user = \User::getById($users_id);
                if ($user) {
                    $this->addToRecipientsList(['users_id' => $users_id, 'language' => $user->fields['language']]);
                }
            }
        } elseif ($target === self::TARGET_NOTICE_GROUP) {
            $groups_id = (int) ($this->options['settings']['notice_groups_id'] ?? 0);
            if ($groups_id > 0) {
                $this->addForGroup(0, $groups_id);
            }
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $contract = $options['contract'] ?? [];
        $settings = $options['settings'] ?? [];
        $data = ContractData::forContract((int) ($contract['id'] ?? 0));
        $totals = Ledger::totals((int) ($contract['id'] ?? 0));

        $this->data['##lifecycle.action##']     = $this->getEvents()[$event] ?? $event;
        $this->data['##contract.id##']          = $contract['id'] ?? '';
        $this->data['##contract.name##']        = $contract['name'] ?? '';
        $this->data['##contract.number##']      = $contract['num'] ?? '';
        $this->data['##contract.entity##']      = \Dropdown::getDropdownName('glpi_entities', (int) ($contract['entities_id'] ?? 0));
        $this->data['##contract.type##']        = !empty($contract['contracttypes_id'])
            ? \Dropdown::getDropdownName(ContractType::getTable(), (int) $contract['contracttypes_id']) : '';
        $this->data['##contract.enddate##']     = Helpers::date(ContractData::endDate($contract, $data));
        $this->data['##contract.daysleft##']    = (string) ($options['days_left'] ?? '');
        $this->data['##contract.url##']         = $this->formatURL($options['additionnaloption']['usertype'] ?? self::GLPI_USER, 'Contract_' . ($contract['id'] ?? 0));
        $this->data['##contract.balance##']     = ($settings['franchise_mode'] ?? '') === 'limited' ? Helpers::hours($totals['balance']) : '';

        $this->getTags();
        foreach ($this->tag_descriptions[self::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'lifecycle.action' => __('Evento', 'contractlifecycle'),
            'contract.id'      => __('ID'),
            'contract.name'    => __('Name'),
            'contract.number'  => _x('phone', 'Number'),
            'contract.entity'  => _n('Entity', 'Entities', 1),
            'contract.type'    => _n('Type', 'Types', 1),
            'contract.enddate' => __('Data final', 'contractlifecycle'),
            'contract.daysleft' => __('Dias até o fim', 'contractlifecycle'),
            'contract.url'     => __('URL'),
            'contract.balance' => __('Saldo de horas', 'contractlifecycle'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
    }
}
