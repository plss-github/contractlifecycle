<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonGLPI;
use Contract;
use ContractType;
use Entity;
use Session;

/**
 * Visão rápida de todos os contratos e saldos (spec 6), agrupada em sub-abas:
 * franquia limitada, franquia ilimitada, cobrança em valor (Per-Event) e sem tipo.
 * Também é a aba "Contratos" da entidade (contratos dela + recursivos herdados das entidades acima).
 */
class Balance extends CommonGLPI
{
    public static $rightname = Ledger::RIGHT;

    public static function getTypeName($nb = 0)
    {
        return __('Contratos (ciclo de vida)', 'contractlifecycle');
    }

    /** Contratos que valem para a entidade: os dela e os recursivos das entidades acima. */
    private static function entityCriteria(int $entities_id): array
    {
        $ancestors = array_map('intval', array_keys(getAncestorsOf('glpi_entities', $entities_id)));
        $or = [['c.entities_id' => $entities_id]];
        if ($ancestors !== []) {
            $or[] = ['c.entities_id' => $ancestors, 'c.is_recursive' => 1];
        }
        return ['OR' => $or];
    }

    public static function rows(bool $only_active, ?int $entities_id = null): array
    {
        global $DB;
        $today = Helpers::today();
        $contracts = [];
        $where = ['c.is_deleted' => 0, 'c.is_template' => 0] + getEntitiesRestrictCriteria('c', '', '', true);
        if ($entities_id !== null) {
            $where[] = self::entityCriteria($entities_id);
        }
        $iterator = $DB->request([
            'SELECT' => ['c.*', 'e.completename AS entity'],
            'FROM'   => Contract::getTable() . ' AS c',
            'LEFT JOIN' => ['glpi_entities AS e' => ['ON' => ['c' => 'entities_id', 'e' => 'id']]],
            'WHERE'  => $where,
            'ORDER'  => ['e.completename', 'c.name'],
        ]);
        foreach ($iterator as $row) {
            $contracts[(int) $row['id']] = $row;
        }
        $totals = Ledger::totalsFor(array_keys($contracts));
        $types = [];

        $groups = ['limited' => [], 'unlimited' => [], 'billing' => [], 'untyped' => []];
        foreach ($contracts as $id => $contract) {
            $data = ContractData::forContract($id);
            $active = ContractData::isActiveOn($contract, $today, $data);
            if ($only_active && !$active) {
                continue;
            }
            $settings = Settings::forContract($id);
            $t = $totals[$id];
            $limited = $settings['franchise_mode'] === 'limited';
            $end = ContractData::endDate($contract, $data);
            $type = (int) $contract['contracttypes_id'];
            $types[$type] ??= $type ? \Dropdown::getDropdownName(ContractType::getTable(), $type) : '—';
            $percent = $limited && $t['credits'] > 0 ? round($t['used'] / $t['credits'] * 100) : null;

            $row = [
                'id'       => $id,
                'name'     => $contract['name'],
                'url'      => Contract::getFormURLWithID($id),
                'entity'   => $contract['entity'],
                'type'     => $types[$type],
                'begin'    => Helpers::date($contract['begin_date']),
                'end'      => Helpers::date($end),
                'active'   => $active,
                'renewal'  => !empty($settings['auto_renewal']),
                'credits'  => Helpers::hours($t['credits']),
                'used'     => Helpers::hours($t['used']),
                'spent'    => Helpers::hours($t['spent']),
                'diff'     => Helpers::hours($t['debited'] - $t['spent'], true),
                'balance'  => Helpers::hours($t['balance']),
                'balance_raw' => $t['balance'],
                'percent'  => $percent,
                'amount'   => Helpers::money($t['amount']),
                'entries'  => $t['entries'],
                'inherited' => $entities_id !== null && (int) $contract['entities_id'] !== $entities_id,
            ];
            if ($type === 0) {
                $groups['untyped'][] = $row; // ainda não classificado (ex.: contratos do QA antes da migração)
            } elseif (!empty($settings['billing_money'])) {
                $groups['billing'][] = $row;
            } elseif ($limited) {
                $groups['limited'][] = $row;
            } else {
                $groups['unlimited'][] = $row;
            }
        }
        return $groups;
    }

    public static function labels(): array
    {
        return [
            'limited'   => __('Franquia limitada (banco de horas)', 'contractlifecycle'),
            'unlimited' => __('Franquia ilimitada (gestão)', 'contractlifecycle'),
            'billing'   => __('Cobrança em valor (Per-Event)', 'contractlifecycle'),
            'untyped'   => __('Sem tipo de contrato', 'contractlifecycle'),
        ];
    }

    // ------------------------------------------------------------------ aba na entidade

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Entity || !Session::haveRight(Ledger::RIGHT, READ)) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs']) {
            global $DB;
            $count = (int) $DB->request([
                'COUNT' => 'n',
                'FROM'  => Contract::getTable() . ' AS c',
                'WHERE' => ['c.is_deleted' => 0, 'c.is_template' => 0, self::entityCriteria((int) $item->getID())],
            ])->current()['n'];
        }
        return self::createTabEntry(self::getTypeName(), $count, $item::getType(), 'ti ti-file-certificate');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Entity) {
            return false;
        }
        $groups = self::rows(false, (int) $item->getID());
        // abre na primeira sub-aba com contratos vigentes
        $first = array_key_first(array_filter($groups, fn ($rows) => array_filter($rows, fn ($r) => $r['active']) !== []))
            ?? array_key_first(array_filter($groups)) ?? 'limited';
        echo Helpers::render('balance.html.twig', [
            'groups'      => $groups,
            'labels'      => self::labels(),
            'entity_mode' => true,
            'first'       => $first,
            'add_url'     => Contract::getFormURL(),
        ]);
        return true;
    }

    public static function show(bool $only_active): void
    {
        echo Helpers::render('balance.html.twig', [
            'first'  => 'limited',
            'groups' => self::rows($only_active),
            'labels' => self::labels(),
            'only_active' => $only_active,
            'self_url'    => Helpers::url('front/balance.php'),
        ]);
    }
}
