<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use CommonGLPI;
use Contract;
use Session;
use Ticket;
use TicketCost;
use TicketTask;
use User;

/**
 * Extrato de horas do contrato. Cada linha é uma entrada (criação, recarga, ajuste, renovação) ou saída (débito)
 * de horas. Nada é apagado: recálculo de um apontamento gera um estorno e um novo débito.
 * Saldo = SUM(direction * debited_seconds).
 */
class Ledger extends CommonDBTM
{
    public const RIGHT = 'plugin_contractlifecycle_ledger';

    public static $rightname = self::RIGHT;

    public const TYPE_CREATION   = 'creation';
    public const TYPE_RECHARGE   = 'recharge';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_RENEWAL    = 'renewal';
    public const TYPE_DEBIT      = 'debit';
    public const TYPE_REVERSAL   = 'reversal';

    /** Evita tratar como "custo manual" os custos que o próprio plugin grava. */
    private static bool $writingCost = false;

    public static function getTypeName($nb = 0)
    {
        return __('Extrato de horas', 'contractlifecycle');
    }

    public static function getIcon()
    {
        return 'ti ti-clock-dollar';
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_CREATION   => __('Criação', 'contractlifecycle'),
            self::TYPE_RECHARGE   => __('Recarga', 'contractlifecycle'),
            self::TYPE_ADJUSTMENT => __('Ajuste', 'contractlifecycle'),
            self::TYPE_RENEWAL    => __('Renovação', 'contractlifecycle'),
            self::TYPE_DEBIT      => __('Apontamento', 'contractlifecycle'),
            self::TYPE_REVERSAL   => __('Estorno', 'contractlifecycle'),
        ];
    }

    // ------------------------------------------------------------------ gravação

    private static function insert(array $row): int
    {
        global $DB;
        $row += [
            'users_id_editor' => (int) Session::getLoginUserID(),
            'date'            => Helpers::now(),
            'date_creation'   => Helpers::now(),
        ];
        $DB->insert(self::getTable(), $row);
        return (int) $DB->insertId();
    }

    /** Entrada ou saída manual/automática de horas (recarga, ajuste, criação, renovação). */
    public static function credit(int $contracts_id, string $type, int $seconds, string $comment = '', int $tickets_id = 0, ?string $date = null): int
    {
        if ($seconds === 0) {
            return 0;
        }
        return self::insert(($date ? ['date' => $date] : []) + [
            'contracts_id'    => $contracts_id,
            'tickets_id'      => $tickets_id,
            'entry_type'      => $type,
            'debited_seconds' => abs($seconds),
            'direction'       => $seconds > 0 ? 1 : -1,
            'comment'         => $comment,
        ]);
    }

    /** Franquia alterada no formulário do contrato. */
    public static function franchiseChanged(int $contracts_id, $old, $new, ?string $begin_date = null): void
    {
        $old_s = $old === null ? null : (int) round((float) $old * 3600);
        $new_s = $new === null ? null : (int) round((float) $new * 3600);
        if ($old_s === $new_s) {
            return;
        }
        global $DB;
        $has_creation = $DB->request(['COUNT' => 'c', 'FROM' => self::getTable(), 'WHERE' => [
            'contracts_id' => $contracts_id, 'entry_type' => self::TYPE_CREATION,
        ]])->current()['c'] > 0;

        if (!$has_creation && $new_s !== null) {
            // a franquia vale desde o início da vigência: o saldo corrente do extrato começa por ela
            $date = !empty($begin_date) && $begin_date <= Helpers::today() ? $begin_date . ' 00:00:00' : null;
            self::credit($contracts_id, self::TYPE_CREATION, $new_s, __('Contrato criado', 'contractlifecycle'), 0, $date);
            return;
        }
        self::credit(
            $contracts_id,
            self::TYPE_ADJUSTMENT,
            ($new_s ?? 0) - ($old_s ?? 0),
            sprintf(
                __('Franquia alterada de %s para %s', 'contractlifecycle'),
                $old_s === null ? '—' : Helpers::hours($old_s),
                $new_s === null ? '—' : Helpers::hours($new_s)
            )
        );
    }

    private static function activeEntry(string $itemtype, int $items_id): ?array
    {
        global $DB;
        return $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => $itemtype, 'items_id' => $items_id,
                'entry_type' => self::TYPE_DEBIT, 'is_reversed' => 0,
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();
    }

    /** Estorna um débito (lançamento inverso) e marca o original. */
    private static function reverse(array $entry, string $reason): void
    {
        global $DB;
        self::insert([
            'contracts_id'    => $entry['contracts_id'],
            'tickets_id'      => $entry['tickets_id'],
            'entry_type'      => self::TYPE_REVERSAL,
            'itemtype'        => $entry['itemtype'],
            'items_id'        => $entry['items_id'],
            'users_id'        => $entry['users_id'],
            'real_seconds'    => -$entry['real_seconds'],
            'debited_seconds' => $entry['debited_seconds'],
            'direction'       => 1,
            'band'            => $entry['band'],
            'unit_value'      => $entry['unit_value'],
            'multiplier'      => $entry['multiplier'],
            'amount'          => -$entry['amount'],
            'reverses_id'     => $entry['id'],
            'comment'         => $reason,
        ]);
        $DB->update(self::getTable(), ['is_reversed' => 1], ['id' => $entry['id']]);
    }

    /**
     * Sincroniza o débito de um apontamento (tarefa ou custo manual) com o estado atual dele.
     *
     * @param array|null $source null = apontamento não existe mais (só estorna)
     */
    public static function syncSource(string $itemtype, int $items_id, ?array $source, string $reason = ''): void
    {
        $existing = self::activeEntry($itemtype, $items_id);
        $tickets_id = (int) ($source['tickets_id'] ?? $existing['tickets_id'] ?? 0);
        $contracts_id = $tickets_id ? TicketState::contractOf($tickets_id) : 0;

        $new = null;
        if ($source !== null && $contracts_id > 0 && (int) $source['seconds'] > 0) {
            $settings = Settings::forContract($contracts_id);
            $kind = $itemtype === TicketTask::class ? 'task' : 'cost';
            if (in_array($kind, (array) $settings['time_sources'], true)) {
                $new = self::computeFor($contracts_id, $settings, $source);
            }
        }

        if ($existing !== null) {
            if ($new !== null && self::sameDebit($existing, $new, $contracts_id)) {
                return;
            }
            self::reverse($existing, $reason ?: __('Apontamento alterado', 'contractlifecycle'));
        }

        $cost_id = (int) ($existing['ticketcosts_id'] ?? 0);
        if ($new === null) {
            if ($cost_id) {
                self::deleteCost($cost_id);
            }
            return;
        }

        $new_id = self::insert([
            'contracts_id'    => $contracts_id,
            'tickets_id'      => $tickets_id,
            'entry_type'      => self::TYPE_DEBIT,
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'users_id'        => (int) $source['users_id'],
            'date'            => $source['start'],
            'real_seconds'    => $new['real'],
            'debited_seconds' => $new['debited'],
            'direction'       => -1,
            'band'            => $new['band'],
            'unit_value'      => $new['unit_value'],
            'multiplier'      => $new['multiplier'],
            'amount'          => $new['amount'],
            'comment'         => $source['comment'] ?? '',
        ]);

        $settings = Settings::forContract($contracts_id);
        if ($itemtype === TicketTask::class && !empty($settings['create_ticketcost'])) {
            $cost_id = self::saveCost($cost_id, $tickets_id, $items_id, $source, $new);
            global $DB;
            $DB->update(self::getTable(), ['ticketcosts_id' => $cost_id], ['id' => $new_id]);
        } elseif ($cost_id) {
            self::deleteCost($cost_id);
        }
    }

    private static function sameDebit(array $existing, array $new, int $contracts_id): bool
    {
        return (int) $existing['contracts_id'] === $contracts_id
            && (int) $existing['real_seconds'] === $new['real']
            && (int) $existing['debited_seconds'] === $new['debited']
            && $existing['band'] === $new['band']
            && abs((float) $existing['amount'] - $new['amount']) < 0.005;
    }

    private static function computeFor(int $contracts_id, array $settings, array $source): array
    {
        $contract = Contract::getById($contracts_id);
        $rate = null;
        if (!empty($settings['billing_money'])) {
            $rate = self::rateFor($contracts_id);
        }
        $calc = new Calculator($settings, (int) ($contract->fields['entities_id'] ?? 0));
        return $calc->compute($source['start'], (int) $source['seconds'], $rate);
    }

    /** Versão da tabela Per-Event do contrato; sem versão, fixa a vigente. */
    public static function rateFor(int $contracts_id): ?array
    {
        $data = ContractData::forContract($contracts_id);
        $rates_id = (int) $data['plugin_contractlifecycle_rates_id'];
        if ($rates_id <= 0) {
            $rates_id = Rate::currentId();
            if ($rates_id > 0) {
                ContractData::upsert($contracts_id, ['plugin_contractlifecycle_rates_id' => $rates_id]);
            }
        }
        $rate = new Rate();
        return $rates_id > 0 && $rate->getFromDB($rates_id) ? $rate->fields : null;
    }

    private static function saveCost(int $cost_id, int $tickets_id, int $task_id, array $source, array $calc): int
    {
        $ticket = Ticket::getById($tickets_id);
        $cost = new TicketCost();
        $input = [
            'tickets_id'  => $tickets_id,
            'name'        => sprintf(__('Tarefa #%d (%s)', 'contractlifecycle'), $task_id, Calculator::bandLabels()[$calc['band']]),
            'comment'     => sprintf(
                __('Lançado pelo ciclo de vida de contratos. Tempo real %s, debitado %s.', 'contractlifecycle'),
                Helpers::hours($calc['real']),
                Helpers::hours($calc['debited'])
            ),
            'begin_date'  => substr($source['start'], 0, 10),
            'end_date'    => substr($source['start'], 0, 10),
            'actiontime'  => $calc['debited'],
            'cost_time'   => round($calc['unit_value'] * $calc['multiplier'], 4),
            'entities_id' => $ticket ? (int) $ticket->fields['entities_id'] : 0,
        ];
        self::$writingCost = true;
        try {
            if ($cost_id > 0 && $cost->getFromDB($cost_id)) {
                $cost->update(['id' => $cost_id] + $input);
                return $cost_id;
            }
            return (int) $cost->add($input);
        } finally {
            self::$writingCost = false;
        }
    }

    private static function deleteCost(int $cost_id): void
    {
        $cost = new TicketCost();
        if ($cost->getFromDB($cost_id)) {
            self::$writingCost = true;
            try {
                $cost->delete(['id' => $cost_id], true);
            } finally {
                self::$writingCost = false;
            }
        }
    }

    private static function isPluginCost(int $cost_id): bool
    {
        global $DB;
        return $DB->request(['COUNT' => 'c', 'FROM' => self::getTable(), 'WHERE' => ['ticketcosts_id' => $cost_id]])->current()['c'] > 0;
    }

    // ------------------------------------------------------------------ fontes

    public static function taskSource(array $task): array
    {
        $start = $task['begin'] ?: $task['date'];
        return [
            'tickets_id' => (int) $task['tickets_id'],
            'users_id'   => (int) ($task['users_id_tech'] ?: $task['users_id']),
            'start'      => $start,
            'seconds'    => (int) $task['actiontime'],
            'comment'    => mb_substr(trim(html_entity_decode(strip_tags((string) $task['content']))), 0, 120),
        ];
    }

    public static function costSource(array $cost): array
    {
        return [
            'tickets_id' => (int) $cost['tickets_id'],
            'users_id'   => 0,
            'start'      => ($cost['begin_date'] ?: Helpers::today()) . ' 12:00:00',
            'seconds'    => (int) $cost['actiontime'],
            'comment'    => (string) $cost['name'],
        ];
    }

    // ------------------------------------------------------------------ hooks

    public static function taskChanged(TicketTask $task): void
    {
        if ($task->getFromDB($task->getID())) {
            self::syncSource(TicketTask::class, (int) $task->getID(), self::taskSource($task->fields));
        }
    }

    public static function taskPurged(TicketTask $task): void
    {
        self::syncSource(TicketTask::class, (int) $task->getID(), null, __('Tarefa excluída', 'contractlifecycle'));
    }

    public static function costChanged(TicketCost $cost): void
    {
        if (self::$writingCost || self::isPluginCost((int) $cost->getID())) {
            return;
        }
        if ($cost->getFromDB($cost->getID())) {
            self::syncSource(TicketCost::class, (int) $cost->getID(), self::costSource($cost->fields));
        }
    }

    public static function costPurged(TicketCost $cost): void
    {
        global $DB;
        if (self::$writingCost) {
            return;
        }
        if (self::isPluginCost((int) $cost->getID())) {
            // alguém apagou o custo gerado pelo plugin: o débito continua, só perde a referência
            $DB->update(self::getTable(), ['ticketcosts_id' => 0], ['ticketcosts_id' => (int) $cost->getID()]);
            return;
        }
        self::syncSource(TicketCost::class, (int) $cost->getID(), null, __('Custo excluído', 'contractlifecycle'));
    }

    /** Recalcula todos os apontamentos do chamado contra o contrato atual dele. */
    public static function resyncTicket(int $tickets_id, string $reason = ''): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => TicketTask::getTable(), 'WHERE' => ['tickets_id' => $tickets_id]]) as $task) {
            self::syncSource(TicketTask::class, (int) $task['id'], self::taskSource($task), $reason);
        }
        foreach ($DB->request(['FROM' => TicketCost::getTable(), 'WHERE' => ['tickets_id' => $tickets_id]]) as $cost) {
            if (!self::isPluginCost((int) $cost['id'])) {
                self::syncSource(TicketCost::class, (int) $cost['id'], self::costSource($cost), $reason);
            }
        }
    }

    public static function hasActiveDebits(int $tickets_id): bool
    {
        global $DB;
        return $DB->request(['COUNT' => 'c', 'FROM' => self::getTable(), 'WHERE' => [
            'tickets_id' => $tickets_id, 'entry_type' => self::TYPE_DEBIT, 'is_reversed' => 0,
        ]])->current()['c'] > 0;
    }

    // ------------------------------------------------------------------ consultas

    /**
     * Totais do contrato.
     * credits: entradas; debits: saídas líquidas (débitos - estornos); spent/debited: tempo real e debitado líquidos.
     */
    public static function totals(int $contracts_id): array
    {
        return self::totalsFor([$contracts_id])[$contracts_id];
    }

    /** @return array<int, array> totais por contrato, numa consulta */
    public static function totalsFor(array $contracts_ids): array
    {
        global $DB;
        // credits: entradas (franquia, recargas, renovações, ajustes positivos); withdrawals: ajustes negativos;
        // debits: apontamentos líquidos; used = debits + withdrawals (o que saiu da franquia)
        $empty = ['credits' => 0, 'withdrawals' => 0, 'debits' => 0, 'used' => 0, 'spent' => 0, 'debited' => 0, 'amount' => 0.0, 'balance' => 0, 'entries' => 0];
        $out = array_fill_keys($contracts_ids, $empty);
        if ($contracts_ids === []) {
            return $out;
        }
        $iterator = $DB->request([
            'SELECT' => [
                'contracts_id', 'entry_type', 'direction',
                new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('debited_seconds') . ') AS debited'),
                new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('real_seconds') . ') AS spent'),
                new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('amount') . ') AS amount'),
                new \Glpi\DBAL\QueryExpression('COUNT(*) AS entries'),
            ],
            'FROM'  => self::getTable(),
            'WHERE' => ['contracts_id' => $contracts_ids],
            'GROUPBY' => ['contracts_id', 'entry_type', 'direction'],
        ]);
        foreach ($iterator as $row) {
            $t = &$out[(int) $row['contracts_id']];
            $signed = (int) $row['direction'] * (int) $row['debited'];
            $t['balance'] += $signed;
            $t['entries'] += (int) $row['entries'];
            if (in_array($row['entry_type'], [self::TYPE_DEBIT, self::TYPE_REVERSAL], true)) {
                $t['debits'] -= $signed; // débito soma, estorno subtrai
                $t['debited'] -= $signed;
                $t['spent'] += (int) $row['spent']; // estornos têm real_seconds negativo
                $t['amount'] += (float) $row['amount'];
            } elseif ($signed >= 0) {
                $t['credits'] += $signed;
            } else {
                $t['withdrawals'] -= $signed;
            }
            $t['used'] = $t['debits'] + $t['withdrawals'];
            unset($t);
        }
        return $out;
    }

    public static function ticketTotals(int $tickets_id): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => [
                new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('real_seconds') . ') AS spent'),
                new \Glpi\DBAL\QueryExpression('SUM(-' . $DB->quoteName('direction') . ' * ' . $DB->quoteName('debited_seconds') . ') AS debited'),
                new \Glpi\DBAL\QueryExpression('SUM(' . $DB->quoteName('amount') . ') AS amount'),
            ],
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id, 'entry_type' => [self::TYPE_DEBIT, self::TYPE_REVERSAL]],
        ])->current();
        return [
            'spent'   => (int) ($row['spent'] ?? 0),
            'debited' => (int) ($row['debited'] ?? 0),
            'amount'  => (float) ($row['amount'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------ aba Extrato no contrato

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Contract || $item->isNewItem() || $withtemplate || !Session::haveRight(self::RIGHT, READ)) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Contract) {
            self::showForContract($item);
        }
        return true;
    }

    /** Linhas do extrato com saldo corrente. Sem estornos: esconde o débito estornado e o estorno (o par zera). */
    public static function statement(int $contracts_id, bool $with_reversals): array
    {
        global $DB;
        $rows = [];
        $balance = 0;
        $task_index = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['contracts_id' => $contracts_id],
            'ORDER' => ['date ASC', 'id ASC'],
        ]);
        $users = [];
        foreach ($iterator as $entry) {
            $hidden = !$with_reversals && ($entry['entry_type'] === self::TYPE_REVERSAL || (int) $entry['is_reversed'] === 1);
            $signed = (int) $entry['direction'] * (int) $entry['debited_seconds'];
            if ($hidden) {
                continue;
            }
            $balance += $signed;
            $uid = (int) $entry['users_id'];
            $users[$uid] ??= $uid ? getUserName($uid) : '—';
            $is_movement = in_array($entry['entry_type'], [self::TYPE_DEBIT, self::TYPE_REVERSAL], true);
            $debited = -$signed;                     // débito: +D; estorno: -D
            $real = (int) $entry['real_seconds'];    // débito: +R; estorno: -R (gravado negativo)
            $rows[] = [
                'id'         => (int) $entry['id'],
                'date'       => $entry['date'],
                'type'       => $entry['entry_type'],
                'origin'     => self::originLabel($entry, $task_index),
                'tickets_id' => (int) $entry['tickets_id'],
                'author'     => $users[$uid],
                'time'       => $is_movement ? Helpers::hours($real) : Helpers::hours($signed, true),
                'debited'    => $is_movement ? Helpers::hours($debited, true) : '—',
                'diff'       => $is_movement ? Helpers::hours($debited - $real, true) : '—',
                'diff_raw'   => $is_movement ? $debited - $real : 0,
                'real_raw'   => $is_movement ? $real : 0,
                'debited_raw' => $is_movement ? $debited : 0,
                'credit_raw' => $is_movement ? 0 : $signed,
                'band'       => $entry['band'] ? (Calculator::bandLabels()[$entry['band']] ?? $entry['band']) : '',
                'amount'     => (float) $entry['amount'],
                'balance'    => Helpers::hours($balance),
                'balance_raw' => $balance,
                'comment'    => (string) $entry['comment'],
                'reversed'   => (int) $entry['is_reversed'] === 1,
                'is_credit'  => $signed > 0,
            ];
        }
        return array_reverse($rows); // mais recente primeiro
    }

    /** Posição (1, 2, 3...) da tarefa dentro do chamado, como pede o extrato ("tarefa #índice"). */
    private static function taskPosition(int $tickets_id, int $tasks_id, array &$cache): int
    {
        global $DB;
        if (!isset($cache[$tickets_id])) {
            $cache[$tickets_id] = [];
            $position = 0;
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => TicketTask::getTable(), 'WHERE' => ['tickets_id' => $tickets_id], 'ORDER' => ['date', 'id']]) as $row) {
                $cache[$tickets_id][(int) $row['id']] = ++$position;
            }
        }
        return $cache[$tickets_id][$tasks_id] ?? 0;
    }

    private static function originLabel(array $entry, array &$task_index): string
    {
        $labels = self::typeLabels();
        $tid = (int) $entry['tickets_id'];
        if (in_array($entry['entry_type'], [self::TYPE_DEBIT, self::TYPE_REVERSAL], true)) {
            if ($entry['itemtype'] === TicketCost::class) {
                $what = sprintf(__('custo #%d', 'contractlifecycle'), (int) $entry['items_id']);
            } else {
                $position = self::taskPosition($tid, (int) $entry['items_id'], $task_index);
                $what = $position > 0
                    ? sprintf(__('tarefa #%d', 'contractlifecycle'), $position)
                    : __('tarefa excluída', 'contractlifecycle');
            }
            return sprintf(
                '%s%s #%d (%s)',
                $entry['entry_type'] === self::TYPE_REVERSAL ? $labels[self::TYPE_REVERSAL] . ': ' : '',
                Ticket::getTypeName(1),
                $tid,
                $what
            );
        }
        return $labels[$entry['entry_type']] . ($tid ? sprintf(' — %s #%d', Ticket::getTypeName(1), $tid) : '');
    }

    public static function showForContract(Contract $contract): void
    {
        $id = (int) $contract->getID();
        $settings = Settings::forContract($id);
        $with_reversals = !empty($_SESSION['plugin_contractlifecycle_reversals']);
        $rows = self::statement($id, $with_reversals);
        $totals = self::totals($id);

        echo Helpers::render('ledger.html.twig', [
            'calendar'       => self::calendarData($contract, $settings, $rows),
            'contracts_id'   => $id,
            'rows'           => $rows,
            'totals'         => [
                'credits' => Helpers::hours($totals['credits']),
                'debits'  => Helpers::hours($totals['used']),
                'spent'   => Helpers::hours($totals['spent']),
                'debited' => Helpers::hours($totals['debited']),
                'diff'    => Helpers::hours($totals['debited'] - $totals['spent'], true),
                'balance' => Helpers::hours($totals['balance']),
                'amount'  => Helpers::money($totals['amount']),
            ],
            'summary'        => self::summary($id),
            'limited'        => $settings['franchise_mode'] === 'limited',
            'billing'        => !empty($settings['billing_money']),
            'with_reversals' => $with_reversals,
            'can_move'       => Session::haveRight(self::RIGHT, CREATE) && $contract->canUpdateItem(),
            'action'         => Helpers::url('front/ledger.form.php'),
            'ticket_url'     => Ticket::getFormURL(), // sem ?id=: o template acrescenta
            'ticket_input'   => \Dropdown::show(Ticket::class, [
                'name' => 'tickets_id', 'display' => false, 'entity' => (int) $contract->fields['entities_id'],
                'entity_sons' => (bool) $contract->fields['is_recursive'], 'width' => '100%',
            ]),
            'rand'           => mt_rand(),
        ]);
    }

    /**
     * Dados do calendário (lançamentos por dia): as linhas do extrato já visíveis, os feriados do período
     * e os dias úteis configurados, para o JS montar o mês.
     */
    private static function calendarData(Contract $contract, array $settings, array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'date'    => substr((string) $row['date'], 0, 10),
                'time'    => substr((string) $row['date'], 11, 5),
                'origin'  => $row['origin'],
                'ticket'  => $row['tickets_id'],
                'author'  => $row['author'],
                'band'    => $row['band'],
                'type'    => $row['type'],
                'real'    => $row['real_raw'],
                'debited' => $row['debited_raw'],
                'credit'  => $row['credit_raw'],
                'amount'  => $row['amount'],
                'comment' => mb_substr($row['comment'], 0, 80),
            ];
        }
        $holidays = [];
        $dates = array_column($entries, 'date');
        if ($dates !== [] && !empty($settings['use_holidays'])) {
            $calc = new Calculator($settings, (int) $contract->fields['entities_id']);
            $day = new \DateTime(min($dates) . ' first day of this month');
            $last = new \DateTime(max(max($dates), Helpers::today()) . ' last day of this month');
            for ($i = 0; $day <= $last && $i < 1200; $i++, $day->modify('+1 day')) {
                if ($calc->isHolidayDate($day->format('Y-m-d'))) {
                    $holidays[] = $day->format('Y-m-d');
                }
            }
        }
        return [
            'entries'      => $entries,
            'holidays'     => $holidays,
            'business_days' => array_map('intval', (array) $settings['business_days']),
            'today'        => Helpers::today(),
            'billing'      => !empty($settings['billing_money']),
            'ticket_url'   => Ticket::getFormURL(),
        ];
    }

    /** Resumo por faixa, por técnico e por mês (só apontamentos líquidos). */
    public static function summary(int $contracts_id): array
    {
        global $DB;
        $out = ['bands' => [], 'authors' => [], 'months' => []];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['contracts_id' => $contracts_id, 'entry_type' => self::TYPE_DEBIT, 'is_reversed' => 0],
        ]);
        $bands = Calculator::bandLabels();
        foreach ($iterator as $e) {
            foreach ([
                'bands'   => $bands[$e['band']] ?? (string) $e['band'],
                'authors' => $e['users_id'] ? getUserName((int) $e['users_id']) : '—',
                'months'  => substr((string) $e['date'], 0, 7),
            ] as $group => $key) {
                $out[$group][$key] ??= ['count' => 0, 'spent' => 0, 'debited' => 0, 'amount' => 0.0];
                $out[$group][$key]['count']++;
                $out[$group][$key]['spent'] += (int) $e['real_seconds'];
                $out[$group][$key]['debited'] += (int) $e['debited_seconds'];
                $out[$group][$key]['amount'] += (float) $e['amount'];
            }
        }
        krsort($out['months']);
        foreach ($out as &$group) {
            foreach ($group as &$v) {
                $v['diff'] = Helpers::hours($v['debited'] - $v['spent'], true);
                $v['spent'] = Helpers::hours($v['spent']);
                $v['debited'] = Helpers::hours($v['debited']);
                $v['amount'] = Helpers::money($v['amount']);
            }
        }
        return $out;
    }

    /** POST da sub-aba Movimentar (recarga/ajuste) e do filtro de estornos. */
    public static function handlePost(array $post): void
    {
        if (isset($post['toggle_reversals'])) {
            $_SESSION['plugin_contractlifecycle_reversals'] = empty($_SESSION['plugin_contractlifecycle_reversals']);
            return;
        }
        Session::checkRight(self::RIGHT, CREATE);
        $contract = new Contract();
        if (!$contract->getFromDB((int) ($post['contracts_id'] ?? 0)) || !$contract->canUpdateItem()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $type = in_array($post['entry_type'] ?? '', [self::TYPE_RECHARGE, self::TYPE_ADJUSTMENT], true) ? $post['entry_type'] : null;
        $hours = (float) str_replace(',', '.', (string) ($post['hours'] ?? '0'));
        $seconds = (int) round($hours * 3600);
        if ($type === null || $seconds === 0 || ($type === self::TYPE_RECHARGE && $seconds < 0)) {
            Session::addMessageAfterRedirect(__('Informe o tipo e as horas (recarga só positiva).', 'contractlifecycle'), false, ERROR);
            return;
        }
        $comment = trim((string) ($post['comment'] ?? ''));
        self::credit((int) $contract->getID(), $type, $seconds, $comment, max(0, (int) ($post['tickets_id'] ?? 0)));
        Session::addMessageAfterRedirect(sprintf(
            __('Lançamento registrado — %s de %s. Saldo atual: %s.', 'contractlifecycle'),
            self::typeLabels()[$type],
            Helpers::hours($seconds, true),
            Helpers::hours(self::totals((int) $contract->getID())['balance'])
        ));
    }
}
