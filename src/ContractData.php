<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use Contract;
use Session;
use Ticket;

/**
 * Dados próprios de cada contrato: data final, franquia contratada, versão da tabela Per-Event.
 * Os campos aparecem no formulário principal do contrato (post_item_form) e a barra de uso no topo (pre_item_form).
 */
class ContractData extends CommonDBTM
{
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return __('Dados do ciclo de vida', 'contractlifecycle');
    }

    /** Linha do contrato (cria vazia se não existir). */
    public static function forContract(int $contracts_id): array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $contracts_id]])->current();
        return $row ?? [
            'id' => 0, 'contracts_id' => $contracts_id, 'end_date' => null, 'franchise_hours' => null,
            'plugin_contractlifecycle_rates_id' => 0, 'tickets_id_origin' => 0, 'renewal_count' => 0, 'notices_sent' => null,
            'pending_activation' => 0, 'planned_begin' => null, 'planned_end' => null,
        ];
    }

    public static function upsert(int $contracts_id, array $values): void
    {
        global $DB;
        $values['date_mod'] = Helpers::now();
        if ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $contracts_id]])->count()) {
            $DB->update(self::getTable(), $values, ['contracts_id' => $contracts_id]);
        } else {
            $DB->insert(self::getTable(), $values + ['contracts_id' => $contracts_id]);
        }
    }

    /** Data final: a informada, ou início + duração (meses) do contrato no GLPI. */
    public static function endDate(array $contract, ?array $data = null): ?string
    {
        $data ??= self::forContract((int) $contract['id']);
        if (!empty($data['end_date'])) {
            return $data['end_date'];
        }
        return self::computedEndDate($contract);
    }

    public static function computedEndDate(array $contract): ?string
    {
        if (empty($contract['begin_date']) || (int) $contract['duration'] <= 0) {
            return null;
        }
        $end = new \DateTime($contract['begin_date']);
        $end->modify('+' . (int) $contract['duration'] . ' months')->modify('-1 day');
        return $end->format('Y-m-d');
    }

    /** Contrato vigente na data: sem início = desde sempre; sem fim = sem prazo. */
    public static function isActiveOn(array $contract, string $date, ?array $data = null): bool
    {
        if (!empty($contract['is_deleted']) || !empty($contract['is_template'])) {
            return false;
        }
        $data ??= self::forContract((int) $contract['id']);
        if (!empty($data['pending_activation'])) {
            return false; // criado pelo Onboard: só vale depois do fechamento do chamado
        }
        if (!empty($contract['begin_date']) && $contract['begin_date'] > $date) {
            return false;
        }
        $end = self::endDate($contract, $data);
        return $end === null || $end >= $date;
    }

    // ------------------------------------------------------------------ formulário do contrato

    public static function postItemForm(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof Contract || !empty($params['options']['withtemplate'])) {
            return;
        }
        $data = self::forContract((int) $item->getID());
        $settings = $item->isNewItem()
            ? Settings::forType((int) ($item->fields['contracttypes_id'] ?? 0))
            : Settings::forContract((int) $item->getID());
        $computed = $item->isNewItem() ? null : self::computedEndDate($item->fields);

        echo Helpers::render('contract_fields.html.twig', [
            'data'       => $data,
            'computed'   => $computed ? Helpers::date($computed) : null,
            'limited'    => $settings['franchise_mode'] === 'limited' || $data['franchise_hours'] !== null,
            'billing'    => (bool) $settings['billing_money'] || $data['plugin_contractlifecycle_rates_id'] > 0,
            'rate_input' => \Dropdown::show(Rate::class, [
                'name' => '_clc[plugin_contractlifecycle_rates_id]',
                'value' => (int) $data['plugin_contractlifecycle_rates_id'],
                'display' => false, 'entity' => -1, 'width' => '100%',
            ]),
        ]);
    }

    public static function preItemForm(array $params): void
    {
        $item = $params['item'] ?? null;
        if ($item instanceof Contract && !$item->isNewItem() && empty($params['options']['withtemplate'])) {
            echo Bar::forContract((int) $item->getID());
        } elseif ($item instanceof Ticket && !$item->isNewItem()) {
            echo Bar::forTicket($item);
        }
    }

    /** item_add do Contract. */
    public static function contractSaved(Contract $contract): void
    {
        self::persist($contract, $contract->fields['begin_date'] ?? null);
    }

    /**
     * pre_item_update do Contract: o core não dispara item_update quando nenhum campo dele muda, então os campos
     * do plugin são gravados aqui (o formulário já checou o direito de atualizar).
     */
    public static function contractPreUpdate(Contract $contract): void
    {
        if (!is_array($contract->input)) {
            return;
        }
        $begin = array_key_exists('begin_date', $contract->input) ? $contract->input['begin_date'] : ($contract->fields['begin_date'] ?? null);
        self::persist($contract, $begin);
        unset($contract->input['_clc']);
    }

    /** Grava os campos _clc[...] e lança a franquia no extrato. */
    private static function persist(Contract $contract, ?string $begin): void
    {
        Settings::clearCache();
        $input = is_array($contract->input) ? ($contract->input['_clc'] ?? null) : null;
        if (!is_array($input)) {
            return;
        }
        $id = (int) $contract->getID();
        $old = self::forContract($id);
        $values = [];

        if (array_key_exists('end_date', $input)) {
            $values['end_date'] = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $input['end_date'])
                ? substr($input['end_date'], 0, 10) : null;
            if ($values['end_date'] && !empty($begin) && $values['end_date'] < $begin) {
                Session::addMessageAfterRedirect(__('A data final é anterior à data inicial; mantida a data anterior.', 'contractlifecycle'), false, ERROR);
                unset($values['end_date']);
            }
        }
        if (array_key_exists('franchise_hours', $input)) {
            $raw = trim(str_replace(',', '.', (string) $input['franchise_hours']));
            $values['franchise_hours'] = $raw === '' ? null : max(0, round((float) $raw, 2));
        }
        if (array_key_exists('plugin_contractlifecycle_rates_id', $input)) {
            $values['plugin_contractlifecycle_rates_id'] = max(0, (int) $input['plugin_contractlifecycle_rates_id']);
        }
        if ($values === []) {
            return;
        }
        self::upsert($id, $values);

        if (array_key_exists('franchise_hours', $values)) {
            Ledger::franchiseChanged($id, $old['franchise_hours'], $values['franchise_hours'], $begin);
        }
    }

    /**
     * Fechamento do chamado de origem do Onboard: o contrato começa a valer na data do fechamento e mantém a
     * duração planejada no formulário (início/fim informados).
     *
     * @return int[] contratos ativados
     */
    public static function activateFromTicket(Ticket $ticket): array
    {
        global $DB;
        $activated = [];
        $date = substr((string) ($ticket->fields['closedate'] ?: Helpers::now()), 0, 10);
        $iterator = $DB->request(['FROM' => self::getTable(), 'WHERE' => [
            'tickets_id_origin' => (int) $ticket->getID(), 'pending_activation' => 1,
        ]]);
        foreach ($iterator as $data) {
            $days = null;
            if (!empty($data['planned_begin']) && !empty($data['planned_end'])) {
                $days = (int) round((strtotime($data['planned_end']) - strtotime($data['planned_begin'])) / 86400);
            }
            $end = $days !== null
                ? (new \DateTime($date))->modify('+' . max(0, $days) . ' days')->format('Y-m-d')
                : $data['end_date'];
            $contract = new Contract();
            $contract->update([
                'id'         => (int) $data['contracts_id'],
                'begin_date' => $date,
                'duration'   => $days !== null ? max(1, (int) round($days / 30.44)) : 0,
            ]);
            self::upsert((int) $data['contracts_id'], ['pending_activation' => 0, 'end_date' => $end]);
            $activated[] = (int) $data['contracts_id'];
        }
        return $activated;
    }

    public static function contractPurged(Contract $contract): void
    {
        global $DB;
        $id = (int) $contract->getID();
        $DB->delete(self::getTable(), ['contracts_id' => $id]);
        $DB->delete(Service::getTable(), ['contracts_id' => $id]);
        $DB->delete(Ledger::getTable(), ['contracts_id' => $id]);
        $DB->update(TicketState::getTable(), ['contracts_id' => 0, 'status' => TicketState::STATUS_NONE], ['contracts_id' => $id]);
        Settings::deleteRow(Contract::class, $id);
    }
}
