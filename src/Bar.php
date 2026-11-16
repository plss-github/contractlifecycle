<?php

namespace GlpiPlugin\Contractlifecycle;

use Contract;
use ContractType;
use Session;
use Ticket;

/**
 * Barra de uso do contrato.
 *  - franquia limitada: horas consumidas sobre o total de entradas (franquia + recargas + ajustes)
 *  - franquia ilimitada: tempo decorrido da vigência
 * Embaixo, em texto pequeno, a data final. No chamado aparece acima de tudo (o JS move para o topo).
 */
class Bar
{
    public static function data(int $contracts_id): ?array
    {
        $contract = Contract::getById($contracts_id);
        if (!$contract) {
            return null;
        }
        $settings = Settings::forContract($contracts_id);
        $data = ContractData::forContract($contracts_id);
        $end = ContractData::endDate($contract->fields, $data);
        $begin = $contract->fields['begin_date'];
        $totals = Ledger::totals($contracts_id);
        $limited = $settings['franchise_mode'] === 'limited';
        $today = Helpers::today();

        if ($limited) {
            $total = max(0, $totals['credits']);
            $used = $totals['used']; // apontamentos + ajustes negativos
            $percent = $total > 0 ? $used / $total * 100 : ($used > 0 ? 100 : 0);
            $label = sprintf(
                __('%1$s usadas de %2$s · saldo %3$s', 'contractlifecycle'),
                Helpers::hours($used),
                Helpers::hours($total),
                Helpers::hours($totals['balance'])
            );
        } else {
            $percent = null;
            if ($begin && $end) {
                $span = max(1, strtotime($end) - strtotime($begin));
                $percent = (strtotime($today) - strtotime($begin)) / $span * 100;
            }
            $label = $percent === null
                ? __('Vigência sem data inicial ou final', 'contractlifecycle')
                : sprintf(__('%s da vigência decorrida', 'contractlifecycle'), max(0, min(100, round($percent))) . '%');
            $label .= ' · ' . sprintf(__('%s gastas', 'contractlifecycle'), Helpers::hours($totals['spent']));
        }

        $status = 'ok';
        if (!empty($data['pending_activation'])) {
            $status = 'pending';
            $percent = null;
            $label = sprintf(__('Aguardando ativação: começa a valer no fechamento do chamado #%d', 'contractlifecycle'), (int) $data['tickets_id_origin']);
        } elseif ($end !== null && $end < $today) {
            $status = 'expired';
        } elseif ($limited && $totals['balance'] < 0) {
            $status = 'over';
        } elseif ($percent !== null && $percent >= 80) {
            $status = 'warning';
        }

        $type = (int) $contract->fields['contracttypes_id'];
        return [
            'id'        => $contracts_id,
            'name'      => $contract->getName(),
            'url'       => Contract::getFormURLWithID($contracts_id),
            'type'      => $type ? \Dropdown::getDropdownName(ContractType::getTable(), $type) : '',
            'limited'   => $limited,
            'percent'   => $percent === null ? null : max(0, min(100, round($percent, 1))),
            'label'     => $label,
            'status'    => $status,
            'end'       => $end ? Helpers::date($end) : null,
            'renewal'   => !empty($settings['auto_renewal']),
            'billing'   => !empty($settings['billing_money']),
            'amount'    => Helpers::money($totals['amount']),
            'over'      => $limited && $totals['balance'] < 0 ? Helpers::hours(-$totals['balance']) : null,
        ];
    }

    public static function forContract(int $contracts_id): string
    {
        $bar = self::data($contracts_id);
        return $bar ? Helpers::render('bar.html.twig', ['bar' => $bar, 'context' => 'contract']) : '';
    }

    public static function forTicket(Ticket $ticket): string
    {
        if (empty(Settings::global()['show_ticket_bar'])) {
            return '';
        }
        $id = (int) $ticket->getID();
        $state = TicketState::get($id);
        $bar = $state['status'] === TicketState::STATUS_LINKED ? self::data((int) $state['contracts_id']) : null;
        $ticketTotals = Ledger::ticketTotals($id);
        return Helpers::render('bar.html.twig', [
            'context'     => 'ticket',
            'bar'         => $bar,
            'state'       => $state,
            'ticket'      => [
                'id'      => $id,
                'spent'   => Helpers::hours($ticketTotals['spent']),
                'debited' => Helpers::hours($ticketTotals['debited']),
                'amount'  => Helpers::money($ticketTotals['amount']),
            ],
            'can_realloc' => Session::haveRight(Ledger::RIGHT, UPDATE) && $ticket->canUpdateItem(),
            'action'      => Helpers::url('front/ticketstate.form.php'),
        ]);
    }
}
