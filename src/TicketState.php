<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use Contract;
use Session;
use Ticket;
use Ticket_Contract;

/**
 * Situação do chamado perante os contratos: contrato vinculado, aprovação Per-Event pendente/recusada,
 * ou re-alocação pendente (categoria/entidade mudou depois de haver horas debitadas).
 * O vínculo em si usa a tabela do core (Ticket_Contract), visível na aba Contratos do chamado.
 */
class TicketState extends CommonDBTM
{
    public static $rightname = 'ticket';

    public const STATUS_NONE     = 'none';
    public const STATUS_LINKED   = 'linked';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_REFUSED  = 'refused';

    public const MODE_AUTO     = 'auto';
    public const MODE_MANUAL   = 'manual';
    public const MODE_PEREVENT = 'perevent';

    /** Evita tratar como manual o vínculo que o próprio plugin grava. */
    private static bool $linking = false;

    /**
     * Vínculo criado pelo próprio chamado (Ticket::handleContractInputs: regras de negócio "atribuir contrato",
     * contrato padrão da entidade ou campo do formulário) não é escolha manual — só a aba Contratos é.
     */
    private static function linkComesFromTicketWrite(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if (($frame['function'] ?? '') === 'handleContractInputs' && is_a($frame['class'] ?? '', Ticket::class, true)) {
                return true;
            }
        }
        return false;
    }

    public static function get(int $tickets_id): array
    {
        global $DB;
        return $DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $tickets_id]])->current() ?? [
            'id' => 0, 'tickets_id' => $tickets_id, 'contracts_id' => 0, 'link_mode' => '',
            'status' => self::STATUS_NONE, 'needs_realloc' => 0,
        ];
    }

    public static function save(int $tickets_id, array $values): void
    {
        global $DB;
        $values['date_mod'] = Helpers::now();
        if ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $tickets_id]])->count()) {
            $DB->update(self::getTable(), $values, ['tickets_id' => $tickets_id]);
        } else {
            $DB->insert(self::getTable(), $values + ['tickets_id' => $tickets_id]);
        }
    }

    public static function contractOf(int $tickets_id): int
    {
        $state = self::get($tickets_id);
        return $state['status'] === self::STATUS_LINKED ? (int) $state['contracts_id'] : 0;
    }

    // ------------------------------------------------------------------ vínculo

    /** Vincula o chamado ao contrato (core + estado), desfazendo o vínculo automático anterior. */
    public static function link(int $tickets_id, int $contracts_id, string $mode): void
    {
        $state = self::get($tickets_id);
        $previous = (int) $state['contracts_id'];
        self::$linking = true;
        try {
            $link = new Ticket_Contract();
            if ($previous > 0 && $previous !== $contracts_id && $state['link_mode'] !== self::MODE_MANUAL
                && $link->getFromDBByCrit(['tickets_id' => $tickets_id, 'contracts_id' => $previous])) {
                $link->delete(['id' => $link->getID()], true);
            }
            if ($contracts_id > 0 && !$link->getFromDBByCrit(['tickets_id' => $tickets_id, 'contracts_id' => $contracts_id])) {
                $link->add(['tickets_id' => $tickets_id, 'contracts_id' => $contracts_id]);
            }
        } finally {
            self::$linking = false;
        }
        self::save($tickets_id, [
            'contracts_id'  => $contracts_id,
            'link_mode'     => $contracts_id > 0 ? $mode : '',
            'status'        => $contracts_id > 0 ? self::STATUS_LINKED : self::STATUS_NONE,
            'needs_realloc' => 0,
        ]);
    }

    /** Roda o encaixe: contrato, aprovação Per-Event ou nada. */
    public static function applyMatch(Ticket $ticket): void
    {
        $id = (int) $ticket->getID();
        $contracts_id = Matcher::find((int) $ticket->fields['entities_id'], (int) $ticket->fields['itilcategories_id']);
        if ($contracts_id > 0) {
            self::link($id, $contracts_id, self::MODE_AUTO);
            return;
        }
        $state = self::get($id);
        if ($state['link_mode'] !== self::MODE_MANUAL) {
            self::link($id, 0, '');
        }
        if (Matcher::needsPerEvent((int) $ticket->fields['itilcategories_id'])) {
            Approval::request($ticket);
        }
    }

    /** O que o encaixe escolheria hoje, sem gravar. */
    public static function wouldMatch(Ticket $ticket): int
    {
        return Matcher::find((int) $ticket->fields['entities_id'], (int) $ticket->fields['itilcategories_id']);
    }

    // ------------------------------------------------------------------ hooks

    public static function ticketAdded(Ticket $ticket): void
    {
        if ($ticket->getFromDB($ticket->getID())) {
            self::applyMatch($ticket);
        }
    }

    public static function ticketUpdated(Ticket $ticket): void
    {
        if (in_array('global_validation', (array) $ticket->updates, true)) {
            Approval::globalValidationChanged($ticket);
        }
        if (in_array('status', (array) $ticket->updates, true) && (int) $ticket->fields['status'] === Ticket::CLOSED) {
            foreach (ContractData::activateFromTicket($ticket) as $contracts_id) {
                FormRunner::note($ticket, sprintf(__('Contrato %s ativado no fechamento do chamado.', 'contractlifecycle'), Contract::getById($contracts_id)->getName()));
            }
        }
        if (!array_intersect(['itilcategories_id', 'entities_id'], (array) $ticket->updates)) {
            return;
        }
        $id = (int) $ticket->getID();
        $state = self::get($id);
        if ($state['link_mode'] === self::MODE_MANUAL) {
            return; // vínculo escolhido por alguém: não muda sozinho
        }
        if (Ledger::hasActiveDebits($id)) {
            // já há horas lançadas: só sinaliza; o botão "Re-alocar horas" refaz quando alguém decidir
            if (self::wouldMatch($ticket) !== (int) $state['contracts_id']) {
                self::save($id, ['needs_realloc' => 1]);
            }
            return;
        }
        self::applyMatch($ticket);
    }

    /** Vínculo adicionado pela aba Contratos do chamado (manual). */
    public static function linkAdded(Ticket_Contract $link): void
    {
        if (self::$linking || self::linkComesFromTicketWrite()) {
            return;
        }
        $tickets_id = (int) $link->fields['tickets_id'];
        $contracts_id = (int) $link->fields['contracts_id'];
        $state = self::get($tickets_id);
        if (Ledger::hasActiveDebits($tickets_id) && (int) $state['contracts_id'] !== $contracts_id) {
            self::save($tickets_id, ['needs_realloc' => 1]);
            Session::addMessageAfterRedirect(__('O chamado já tem horas lançadas em outro contrato: use "Re-alocar horas" para movê-las.', 'contractlifecycle'), false, WARNING);
            return;
        }
        self::save($tickets_id, [
            'contracts_id' => $contracts_id, 'link_mode' => self::MODE_MANUAL,
            'status' => self::STATUS_LINKED, 'needs_realloc' => 0,
        ]);
        Ledger::resyncTicket($tickets_id);
    }

    public static function linkPurged(Ticket_Contract $link): void
    {
        if (self::$linking) {
            return;
        }
        $tickets_id = (int) $link->fields['tickets_id'];
        $state = self::get($tickets_id);
        if ((int) $state['contracts_id'] !== (int) $link->fields['contracts_id']) {
            return;
        }
        if (Ledger::hasActiveDebits($tickets_id)) {
            self::save($tickets_id, ['needs_realloc' => 1]);
            return;
        }
        self::save($tickets_id, ['contracts_id' => 0, 'link_mode' => '', 'status' => self::STATUS_NONE]);
    }

    public static function ticketPurged(Ticket $ticket): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['tickets_id' => (int) $ticket->getID()]);
    }

    /**
     * Botão "Re-alocar horas": refaz o encaixe e move os lançamentos do chamado para o contrato atual.
     * Vínculo manual vigente é respeitado (só recalcula contra ele).
     */
    public static function realloc(int $tickets_id): void
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem() || !Session::haveRight(Ledger::RIGHT, UPDATE)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $state = self::get($tickets_id);
        $manual = $state['link_mode'] === self::MODE_MANUAL ? (int) $state['contracts_id'] : 0;
        if ($manual > 0) {
            $link = new Ticket_Contract();
            if (!$link->getFromDBByCrit(['tickets_id' => $tickets_id, 'contracts_id' => $manual])) {
                $manual = 0; // o vínculo manual foi removido
            }
        }
        if ($manual > 0) {
            self::save($tickets_id, ['needs_realloc' => 0]);
        } else {
            self::save($tickets_id, ['link_mode' => self::MODE_AUTO]);
            self::applyMatch($ticket);
        }
        Ledger::resyncTicket($tickets_id, __('Re-alocação de horas', 'contractlifecycle'));
        $new = self::contractOf($tickets_id);
        Session::addMessageAfterRedirect($new > 0
            ? sprintf(__('Horas re-alocadas para o contrato %s.', 'contractlifecycle'), Contract::getById($new)->getName())
            : __('Nenhum contrato cobre o chamado: as horas foram estornadas.', 'contractlifecycle'));
    }
}
