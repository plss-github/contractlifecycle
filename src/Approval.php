<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonITILValidation;
use Contract;
use Document_Item;
use Entity;
use Group;
use Session;
use Ticket;
use TicketTask;
use TicketValidation;
use User;

/**
 * Per-Event: chamado sem contrato numa categoria elegível (raiz IT Support) abre aprovação para os pontos focais.
 * Aprovado: cria o contrato Per-Event da entidade (vigência base global, tabela vigente) e vincula o chamado.
 * Recusado: o plugin não faz mais nada (o chamado só não é atendido). Enquanto não aprova, o apontamento pode
 * ficar bloqueado (block_until_approved).
 */
class Approval
{
    public static function request(Ticket $ticket): void
    {
        $id = (int) $ticket->getID();
        $state = TicketState::get($id);
        if (in_array($state['status'], [TicketState::STATUS_PENDING, TicketState::STATUS_REFUSED], true)) {
            return;
        }
        $global = Settings::global();
        $entities_id = (int) $ticket->fields['entities_id'];
        $users = Focal::usersFor($entities_id, (array) $global['approval_roles']);
        $comment = self::proposalText();

        $validation = new TicketValidation();
        $created = 0;
        foreach ($users as $users_id) {
            $created += $validation->add([
                'tickets_id'         => $id,
                'itemtype_target'    => User::class,
                'items_id_target'    => $users_id,
                'comment_submission' => $comment,
            ]) ? 1 : 0;
        }
        if ($created === 0 && (int) $global['approval_groups_id'] > 0) {
            $created += $validation->add([
                'tickets_id'         => $id,
                'itemtype_target'    => Group::class,
                'items_id_target'    => (int) $global['approval_groups_id'],
                'comment_submission' => $comment,
            ]) ? 1 : 0;
        }

        TicketState::save($id, ['contracts_id' => 0, 'link_mode' => TicketState::MODE_PEREVENT, 'status' => TicketState::STATUS_PENDING]);

        if (Session::getLoginUserID()) {
            Session::addMessageAfterRedirect(
                $created > 0
                    ? __('Nenhum contrato cobre este serviço: aprovação Per-Event enviada ao ponto focal.', 'contractlifecycle')
                    : __('Nenhum contrato cobre este serviço e a entidade não tem ponto focal nem grupo aprovador configurado: cadastre um aprovador para liberar o atendimento.', 'contractlifecycle'),
                false,
                $created > 0 ? INFO : WARNING
            );
        }
    }

    /** Texto da proposta padrão, com a versão vigente da tabela e o link do documento anexado a ela. */
    public static function proposalText(): string
    {
        $rate = new Rate();
        $lines = [__('Este chamado não é coberto por nenhum contrato ativo. Para atendê-lo, é necessário aprovar o atendimento avulso (Per-Event).', 'contractlifecycle')];
        if (($rates_id = Rate::currentId()) > 0 && $rate->getFromDB($rates_id)) {
            $lines[] = sprintf(
                __('Tabela %1$s: %2$s por hora; fora do horário comercial ×%3$s; fins de semana e feriados ×%4$s.', 'contractlifecycle'),
                $rate->fields['name'],
                Helpers::money((float) $rate->fields['hourly_value']),
                rtrim(rtrim(number_format((float) $rate->fields['mult_offhours'], 2, ',', ''), '0'), ','),
                rtrim(rtrim(number_format((float) $rate->fields['mult_weekend'], 2, ',', ''), '0'), ',')
            );
            global $DB, $CFG_GLPI;
            foreach ($DB->request(['FROM' => Document_Item::getTable(), 'WHERE' => ['itemtype' => Rate::class, 'items_id' => $rates_id]]) as $doc) {
                $lines[] = sprintf(__('Proposta: %s', 'contractlifecycle'), $CFG_GLPI['url_base'] . '/front/document.send.php?docid=' . (int) $doc['documents_id']);
            }
        }
        return implode("\n\n", $lines);
    }

    /**
     * item_update de TicketValidation. No GLPI 11 o status global do chamado é recalculado pelas etapas de
     * validação depois deste hook; o sinal definitivo vem por globalValidationChanged() (item_update do Ticket).
     */
    public static function validationUpdated(TicketValidation $validation): void
    {
        $ticket = new Ticket();
        if ($ticket->getFromDB((int) $validation->fields['tickets_id'])) {
            self::globalValidationChanged($ticket);
        }
    }

    /** Status global de aprovação do chamado mudou: aprovado → cria o contrato; recusado → marca. */
    public static function globalValidationChanged(Ticket $ticket): void
    {
        $tickets_id = (int) $ticket->getID();
        $state = TicketState::get($tickets_id);
        if ($state['link_mode'] !== TicketState::MODE_PEREVENT || $state['status'] !== TicketState::STATUS_PENDING) {
            return;
        }
        $global_status = (int) $ticket->fields['global_validation'];
        if ($global_status === CommonITILValidation::ACCEPTED) {
            $contracts_id = self::createContract($ticket);
            TicketState::link($tickets_id, $contracts_id, TicketState::MODE_PEREVENT);
            Ledger::resyncTicket($tickets_id);
        } elseif ($global_status === CommonITILValidation::REFUSED) {
            TicketState::save($tickets_id, ['status' => TicketState::STATUS_REFUSED]);
        }
    }

    public static function createContract(Ticket $ticket): int
    {
        $global = Settings::global();
        $entities_id = (int) $ticket->fields['entities_id'];
        $today = Helpers::today();
        $end = (new \DateTime($today))->modify('+' . max(1, (int) $global['perevent_duration_days']) . ' days')->format('Y-m-d');

        $contract = new Contract();
        $contracts_id = (int) $contract->add([
            'name'             => sprintf('PER-EVENT - %s - %s', Entity::getById($entities_id)?->getName() ?? $entities_id, \Html::convDate($today)),
            'entities_id'      => $entities_id,
            'is_recursive'     => 0,
            'contracttypes_id' => (int) $global['perevent_contracttypes_id'],
            'begin_date'       => $today,
            'duration'         => 0,
            'comment'          => sprintf(__('Criado pela aprovação Per-Event do chamado #%d.', 'contractlifecycle'), $ticket->getID()),
        ]);
        ContractData::upsert($contracts_id, [
            'end_date'                          => $end,
            'plugin_contractlifecycle_rates_id' => Rate::currentId(),
            'tickets_id_origin'                 => (int) $ticket->getID(),
        ]);
        return $contracts_id;
    }

    /** pre_item_add de TicketTask: bloqueia apontamento enquanto a aprovação Per-Event não sai. */
    public static function preTaskAdd(TicketTask $task): void
    {
        if (!is_array($task->input) || empty(Settings::global()['block_until_approved'])) {
            return;
        }
        $state = TicketState::get((int) ($task->input['tickets_id'] ?? 0));
        if ($state['link_mode'] !== TicketState::MODE_PEREVENT) {
            return;
        }
        if ($state['status'] === TicketState::STATUS_PENDING || $state['status'] === TicketState::STATUS_REFUSED) {
            $task->input = false;
            Session::addMessageAfterRedirect(
                $state['status'] === TicketState::STATUS_PENDING
                    ? __('Atendimento aguardando a aprovação Per-Event do ponto focal: a tarefa não foi registrada.', 'contractlifecycle')
                    : __('A aprovação Per-Event foi recusada: o chamado não pode ser atendido.', 'contractlifecycle'),
                false,
                ERROR
            );
        }
    }
}
