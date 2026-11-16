<?php

/**
 * Harness de regras do contractlifecycle, rodando no laboratório (dados do QA sincronizados).
 *   docker compose exec -T glpi php /var/www/glpi/plugins/contractlifecycle/tests/lab/run.php
 * Cria o que precisa e apaga no fim (contratos/chamados com prefixo "LABTEST").
 */

ob_start(); // a troca de sessão (login como aprovador) precisa que nada tenha sido enviado ainda
register_shutdown_function(fn () => ob_get_level() && ob_end_flush());
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Contractlifecycle\Approval;
use GlpiPlugin\Contractlifecycle\Calculator;
use GlpiPlugin\Contractlifecycle\ContractData;
use GlpiPlugin\Contractlifecycle\Cron;
use GlpiPlugin\Contractlifecycle\Focal;
use GlpiPlugin\Contractlifecycle\Ledger;
use GlpiPlugin\Contractlifecycle\Rate;
use GlpiPlugin\Contractlifecycle\Service;
use GlpiPlugin\Contractlifecycle\Settings;
use GlpiPlugin\Contractlifecycle\TicketState;

$auth = new Auth();
$auth->user = new User();
$auth->user->getFromDBbyName('glpi');
$auth->auth_succeded = true;
Session::init($auth);
Session::changeActiveEntities(0, true);

global $DB;
// As regras "CONTRATO - Atribuição..." do QA vinculam contratos por categoria; desligadas durante o teste
// (reativadas no fim), exceto onde a convivência é testada.
$contract_rules = [];
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_rules', 'WHERE' => ['sub_type' => 'RuleTicket', 'name' => ['LIKE', 'CONTRATO -%'], 'is_active' => 1]]) as $row) {
    $contract_rules[] = (int) $row['id'];
}
if ($contract_rules) {
    $DB->update('glpi_rules', ['is_active' => 0], ['id' => $contract_rules]);
}
register_shutdown_function(function () use ($contract_rules) {
    global $DB;
    if ($contract_rules) {
        $DB->update('glpi_rules', ['is_active' => 1], ['id' => $contract_rules]);
    }
});

// resíduos de uma execução interrompida
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_entities', 'WHERE' => ['name' => 'LABTEST']]) as $row) {
    $DB->delete('glpi_itilcategories', ['entities_id' => $row['id']]);
    $DB->delete('glpi_plugin_contractlifecycle_focals', ['entities_id' => $row['id']]);
    (new Entity())->delete(['id' => $row['id']], true);
}
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_rules', 'WHERE' => ['name' => ['LIKE', 'LABTEST%']]]) as $row) {
    (new RuleTicket())->delete(['id' => $row['id']], true);
}
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', 'LABTEST%']]]) as $row) {
    (new Ticket())->delete(['id' => $row['id']], true);
}
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_contracts', 'WHERE' => ['OR' => [['name' => ['LIKE', 'LABTEST%']], ['comment' => ['LIKE', '%Per-Event do chamado%']]]]]) as $row) {
    (new Contract())->delete(['id' => $row['id']], true);
}
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_plugin_contractlifecycle_rates', 'WHERE' => ['name' => ['LIKE', 'LABTEST%']]]) as $row) {
    (new \GlpiPlugin\Contractlifecycle\Rate())->delete(['id' => $row['id']], true);
}

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? "  PASS " : "  FAIL ") . $label . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    $fails += $ok ? 0 : 1;
}
function login(string $name): void
{
    $auth = new Auth();
    $auth->user = new User();
    $auth->user->getFromDBbyName($name);
    $auth->auth_succeded = true;
    Session::init($auth);
    Session::changeActiveEntities(0, true);
}
function section(string $title): void
{
    echo "\n== $title\n";
}
function h(int $s): string
{
    return \GlpiPlugin\Contractlifecycle\Helpers::hours($s);
}

// ------------------------------------------------------------------------------------------------
section('Calculadora: faixas e cobrança mínima (Banco de Horas: 15 / 60 / 60)');

$base = array_merge(Settings::forType(2), ['calendars_id' => 1, 'use_holidays' => 1]);
$c = new Calculator($base, 3);
$r = $c->compute('2026-11-18 10:00:00', 5 * 60);           // quarta, comercial
check('5 min às 10h de quarta → comercial, debita 15 min', $r['band'] === 'business' && $r['debited'] === 900, json_encode($r));
$r = $c->compute('2026-11-18 10:00:00', 40 * 60);
check('40 min no comercial → debita 40 min (mínimo não arredonda para cima)', $r['debited'] === 2400, h($r['debited']));
$r = $c->compute('2026-11-18 19:00:00', 20 * 60);
check('20 min às 19h → fora do horário, debita 1h', $r['band'] === 'offhours' && $r['debited'] === 3600, json_encode($r));
$r = $c->compute('2026-11-18 07:30:00', 20 * 60);
check('20 min às 7h30 → fora do horário', $r['band'] === 'offhours', $r['band']);
$r = $c->compute('2026-11-21 10:00:00', 20 * 60);           // sábado
check('sábado → fim de semana, debita 1h', $r['band'] === 'weekend' && $r['debited'] === 3600, json_encode($r));
$r = $c->compute('2026-11-20 10:00:00', 20 * 60);           // feriado Consciência Negra
check('feriado (20/11, calendário 1) → fim de semana', $r['band'] === 'weekend', $r['band']);
$r = $c->compute('2026-11-18 10:00:00', 90 * 60);
check('1h30 no comercial → debita 1h30', $r['debited'] === 5400);

section('Calculadora: apontamento cruzando 18h (17h50 → 18h05, 15 min)');
foreach ([
    ['start', 0, 'business', 900],
    ['end', 0, 'offhours', 3600],
    ['business', 0, 'business', 900],
    ['nonbusiness', 0, 'offhours', 3600],
    ['start', 1, 'offhours', 3600],      // arredondar: vale a faixa de maior cobrança
    ['business', 1, 'offhours', 3600],
] as [$rule, $rounding, $band, $debited]) {
    $calc = new Calculator(array_merge($base, ['boundary_rule' => $rule, 'rounding' => $rounding]), 3);
    $r = $calc->compute('2026-11-18 17:50:00', 15 * 60);
    check("prevalecer=$rule arredondar=$rounding → $band / " . h($debited), $r['band'] === $band && $r['debited'] === $debited, json_encode($r));
}

section('Calculadora: empate com arredondar (sexta 23h50 → sábado 00h10: fora do horário x fim de semana, ambos 1h)');
foreach ([['start', 'offhours'], ['end', 'weekend']] as [$rule, $band]) {
    $calc = new Calculator(array_merge($base, ['boundary_rule' => $rule, 'rounding' => 1]), 3);
    $r = $calc->compute('2026-11-27 23:50:00', 20 * 60);
    check("empate segue prevalecer=$rule → $band", $r['band'] === $band, json_encode($r));
}

section('Calculadora: cobrança em valor (R$ 100/h; ×1 / ×1,5 / ×2; mínimo 1h)');
$rate = ['hourly_value' => 100, 'mult_business' => 1, 'mult_offhours' => 1.5, 'mult_weekend' => 2];
$pe = array_merge(Settings::forType(3), ['calendars_id' => 1, 'use_holidays' => 1]);
$calc = new Calculator($pe, 3);
$r = $calc->compute('2026-11-18 10:00:00', 30 * 60, $rate);
check('30 min comercial → 1h × R$100 = R$100', $r['debited'] === 3600 && abs($r['amount'] - 100) < 0.01, json_encode($r));
$r = $calc->compute('2026-11-18 20:00:00', 90 * 60, $rate);
check('1h30 fora do horário → R$225', abs($r['amount'] - 225) < 0.01, json_encode($r));
$r = $calc->compute('2026-11-21 09:00:00', 60 * 60, $rate);
check('1h sábado → R$200', abs($r['amount'] - 200) < 0.01, json_encode($r));
$calc = new Calculator(array_merge($pe, ['rounding' => 1, 'boundary_rule' => 'start']), 3);
$r = $calc->compute('2026-11-18 17:30:00', 60 * 60, $rate);
check('17h30–18h30 com arredondar → faixa mais cara (fora do horário, R$150)', $r['band'] === 'offhours' && abs($r['amount'] - 150) < 0.01, json_encode($r));

// ------------------------------------------------------------------------------------------------
section('Cenário Pamplona: banco de horas, vínculo automático, débito, custos, recálculo');

// Entidade e catálogo próprios do teste (isolado da demo e dos contratos reais)
$entity = new Entity();
$ENTITY = (int) $entity->add(['name' => 'LABTEST', 'entities_id' => 1]);
Session::changeActiveEntities(0, true);
$category = new ITILCategory();
$cat = function (string $name, int $parent = 0) use ($category, $ENTITY): int {
    return (int) $category->add(['name' => $name, 'itilcategories_id' => $parent, 'entities_id' => $ENTITY, 'is_recursive' => 0]);
};
$cloud = $cat('Cloud');
$CAT_ORACLE = $cat('Oracle', $cat('Banco de Dados', $cloud));
$itsupport = $cat('IT Support');
$CAT_SO = $cat('Sistema Operacional', $itsupport);
$CAT_PE = $cat('Banco de Dados', $itsupport);
$CAT_PE2 = $cat('Virtualização', $itsupport);
define('ENTITY', $ENTITY);
define('CAT_ORACLE', $CAT_ORACLE);
define('CAT_SO', $CAT_SO);
define('CAT_PE', $CAT_PE);

$created = ['Contract' => [], 'Ticket' => []];
$contract = new Contract();
$horas = (int) $contract->add([
    'name' => 'LABTEST - Banco de horas', 'entities_id' => ENTITY, 'contracttypes_id' => 2,
    'begin_date' => '2026-01-01', 'duration' => 12,
    '_clc' => ['franchise_hours' => '10', 'end_date' => ''],
]);
$created['Contract'][] = $horas;
Service::addCategory($horas, CAT_ORACLE);
$t = Ledger::totals($horas);
check('franquia de 10h lançada como "Criação" no extrato', $t['credits'] === 36000 && $t['balance'] === 36000, json_encode($t));
check('data final calculada = início + 12 meses - 1 dia', ContractData::endDate($contract->fields) === '2026-12-31', (string) ContractData::endDate($contract->fields));

$ticket = new Ticket();
$tid = (int) $ticket->add([
    'name' => 'LABTEST - chamado Oracle', 'content' => 'teste', 'entities_id' => ENTITY,
    'itilcategories_id' => CAT_ORACLE, '_users_id_requester' => 2,
]);
$created['Ticket'][] = $tid;
$state = TicketState::get($tid);
check('chamado novo vinculado automaticamente ao contrato', (int) $state['contracts_id'] === $horas && $state['status'] === 'linked', json_encode($state));
$link = new Ticket_Contract();
check('vínculo gravado também no core (aba Contratos do chamado)', $link->getFromDBByCrit(['tickets_id' => $tid, 'contracts_id' => $horas]));

$task = new TicketTask();
$task_id = (int) $task->add(['tickets_id' => $tid, 'content' => 'Ajuste no listener', 'actiontime' => 300, 'date' => '2026-11-18 10:00:00', 'users_id_tech' => 10]);
$t = Ledger::totals($horas);
check('tarefa de 5 min debita 15 min', $t['debited'] === 900 && $t['spent'] === 300 && $t['balance'] === 36000 - 900, json_encode($t));
$cost = new TicketCost();
check('custo lançado no chamado com o tempo debitado', $cost->getFromDBByCrit(['tickets_id' => $tid]) && (int) $cost->fields['actiontime'] === 900);

$task->update(['id' => $task_id, 'actiontime' => 2400]);
$t = Ledger::totals($horas);
check('tarefa alterada para 40 min → estorno + novo débito de 40 min', $t['debited'] === 2400 && $t['spent'] === 2400, json_encode($t));
$cost->getFromDB($cost->getID());
check('custo atualizado (não duplicado)', (int) $cost->fields['actiontime'] === 2400 && countElementsInTable('glpi_ticketcosts', ['tickets_id' => $tid]) === 1);
$rows = Ledger::statement($horas, false);
check('extrato sem estornos mostra criação + 1 débito', count($rows) === 2, (string) count($rows));
check('extrato com estornos mostra criação + débito estornado + estorno + débito', count(Ledger::statement($horas, true)) === 4);

$task2 = new TicketTask();
$task2_id = (int) $task2->add(['tickets_id' => $tid, 'content' => 'Plantão', 'actiontime' => 1200, 'date' => '2026-11-21 10:00:00']);
$t = Ledger::totals($horas);
check('tarefa de sábado (20 min) debita 1h', $t['debited'] === 2400 + 3600, json_encode($t));
$task2->delete(['id' => $task2_id], true);
$t = Ledger::totals($horas);
check('tarefa excluída → estorno, saldo volta', $t['debited'] === 2400 && $t['balance'] === 36000 - 2400, json_encode($t));
check('custo da tarefa excluída removido', countElementsInTable('glpi_ticketcosts', ['tickets_id' => $tid]) === 1);

section('Saldo negativo e recarga (150h usadas de 100h, recarga de 200h → saldo 150h)');
$neg = (int) $contract->add([
    'name' => 'LABTEST - Saldo negativo', 'entities_id' => ENTITY, 'contracttypes_id' => 2,
    'begin_date' => '2026-01-01', 'duration' => 12, '_clc' => ['franchise_hours' => '100'],
]);
$created['Contract'][] = $neg;
Ledger::credit($neg, Ledger::TYPE_ADJUSTMENT, -150 * 3600, 'consumo simulado');
check('saldo -50h', Ledger::totals($neg)['balance'] === -50 * 3600);
Ledger::credit($neg, Ledger::TYPE_RECHARGE, 200 * 3600, 'recarga');
check('após recarga de 200h, saldo 150h', Ledger::totals($neg)['balance'] === 150 * 3600, h(Ledger::totals($neg)['balance']));
$contract->update(['id' => $neg, '_clc' => ['franchise_hours' => '120']]);
check('franquia alterada 100h → 120h lança ajuste de +20h', Ledger::totals($neg)['balance'] === 170 * 3600, h(Ledger::totals($neg)['balance']));

section('Convivência com regra de negócio "atribuir contrato" (como as "CONTRATO - Atribuição..." do QA)');
$rule = new RuleTicket();
$rule_id = (int) $rule->add(['name' => 'LABTEST - atribuição de contrato', 'sub_type' => 'RuleTicket', 'match' => 'OR', 'is_active' => 1,
    'entities_id' => 0, 'is_recursive' => 1, 'condition' => 3, 'ranking' => 0]);
(new RuleCriteria())->add(['rules_id' => $rule_id, 'criteria' => 'itilcategories_id', 'condition' => 0, 'pattern' => CAT_ORACLE]);
(new RuleAction())->add(['rules_id' => $rule_id, 'action_type' => 'assign', 'field' => 'assign_contract', 'value' => 2]);
$rt = (int) $ticket->add(['name' => 'LABTEST - regra', 'content' => 'x', 'entities_id' => ENTITY, 'itilcategories_id' => CAT_ORACLE]);
$created['Ticket'][] = $rt;
$s = TicketState::get($rt);
check('regra vincula o contrato 2 no core, mas o plugin mantém o próprio encaixe (automático)', $s['link_mode'] === 'auto' && (int) $s['contracts_id'] === $horas
    && (new Ticket_Contract())->getFromDBByCrit(['tickets_id' => $rt, 'contracts_id' => 2]), json_encode($s));
$rule->delete(['id' => $rule_id], true);

section('Mais de um contrato: ilimitado vence o limitado (estratégia padrão)');
$gestao = (int) $contract->add([
    'name' => 'LABTEST - Gestão', 'entities_id' => ENTITY, 'contracttypes_id' => 1,
    'begin_date' => '2026-01-01', 'duration' => 12,
]);
$created['Contract'][] = $gestao;
Service::addCategory($gestao, CAT_ORACLE);
$t2 = (int) $ticket->add(['name' => 'LABTEST - chamado 2', 'content' => 'x', 'entities_id' => ENTITY, 'itilcategories_id' => CAT_ORACLE]);
$created['Ticket'][] = $t2;
check('novo chamado vai para o contrato de gestão (ilimitado)', TicketState::contractOf($t2) === $gestao, (string) TicketState::contractOf($t2));
$g = Settings::getRow('global', 0);
Settings::saveRow('global', 0, ['match_strategy' => 'limited_first'] + $g);
$t3 = (int) $ticket->add(['name' => 'LABTEST - chamado 3', 'content' => 'x', 'entities_id' => ENTITY, 'itilcategories_id' => CAT_ORACLE]);
$created['Ticket'][] = $t3;
check('com "limitado primeiro", vai para o banco de horas', TicketState::contractOf($t3) === $horas, (string) TicketState::contractOf($t3));
Settings::saveRow('global', 0, $g);

section('Troca de categoria: sem horas re-vincula; com horas pede re-alocação');
$ticket->update(['id' => $t2, 'itilcategories_id' => CAT_SO]);
$s = TicketState::get($t2);
check('chamado sem horas trocado para IT Support → sai do contrato e abre aprovação Per-Event', $s['status'] === 'pending' && (int) $s['contracts_id'] === 0, json_encode($s));

$ticket->update(['id' => $tid, 'itilcategories_id' => CAT_SO]);
$s = TicketState::get($tid);
check('chamado com horas: mantém contrato e marca "Re-alocar horas"', (int) $s['contracts_id'] === $horas && (int) $s['needs_realloc'] === 1, json_encode($s));
Service::addCategory($gestao, CAT_SO);
TicketState::realloc($tid);
$s = TicketState::get($tid);
check('re-alocar: chamado vai para o contrato de gestão', (int) $s['contracts_id'] === $gestao && (int) $s['needs_realloc'] === 0, json_encode($s));
check('re-alocar: horas saem do banco de horas', Ledger::totals($horas)['debited'] === 0, json_encode(Ledger::totals($horas)));
check('re-alocar: horas entram na gestão (40 min)', Ledger::totals($gestao)['debited'] === 2400, json_encode(Ledger::totals($gestao)));

section('Per-Event: aprovação do ponto focal, bloqueio e criação do contrato');
$DB->delete(Focal::getTable(), ['entities_id' => ENTITY]);
$DB->insert(Focal::getTable(), ['entities_id' => ENTITY, 'role' => 'contract', 'users_id' => 11]);
$rate_obj = new Rate();
$rate_id = (int) $rate_obj->add(['name' => 'LABTEST 2026', 'valid_from' => '2026-01-01', 'hourly_value' => '100', 'mult_business' => 1, 'mult_offhours' => 1.5, 'mult_weekend' => 2]);

$pe_ticket = (int) $ticket->add(['name' => 'LABTEST - per event', 'content' => 'x', 'entities_id' => ENTITY, 'itilcategories_id' => CAT_PE]);
$created['Ticket'][] = $pe_ticket;
$s = TicketState::get($pe_ticket);
check('chamado IT Support sem contrato → aprovação pendente', $s['status'] === 'pending', json_encode($s));
$val = new TicketValidation();
check('aprovação criada para o ponto focal (contrato)', $val->getFromDBByCrit(['tickets_id' => $pe_ticket, 'itemtype_target' => 'User', 'items_id_target' => 11]));
check('texto da proposta traz a tabela vigente', str_contains((string) $val->fields['comment_submission'], 'LABTEST 2026'), (string) $val->fields['comment_submission']);
$blocked = (new TicketTask())->add(['tickets_id' => $pe_ticket, 'content' => 'x', 'actiontime' => 600, 'date' => '2026-11-18 10:00:00']);
check('tarefa bloqueada enquanto não aprova', $blocked === false);

login('user.gerente');   // só o aprovador pode responder
$answered = $val->update(['id' => $val->getID(), 'status' => CommonITILValidation::ACCEPTED, 'comment_validation' => 'ok']);
login('glpi');
check('ponto focal aprova a validação', (bool) $answered);
$s = TicketState::get($pe_ticket);
check('aprovado → contrato Per-Event criado e vinculado', $s['status'] === 'linked' && (int) $s['contracts_id'] > 0, json_encode($s));
$pe_contract = (int) $s['contracts_id'];
$created['Contract'][] = $pe_contract;
$data = ContractData::forContract($pe_contract);
check('contrato Per-Event com vigência base (30 dias) e tabela vigente', $data['plugin_contractlifecycle_rates_id'] == $rate_id && $data['end_date'] === date('Y-m-d', strtotime(\GlpiPlugin\Contractlifecycle\Helpers::today() . ' +30 days')), json_encode($data));
$ok = (new TicketTask())->add(['tickets_id' => $pe_ticket, 'content' => 'atendimento', 'actiontime' => 1800, 'date' => '2026-11-18 20:00:00']);
check('após aprovação, tarefa entra (30 min às 20h → 1h × R$100 × 1,5 = R$150)', $ok && abs(Ledger::totals($pe_contract)['amount'] - 150) < 0.01, json_encode(Ledger::totals($pe_contract)));
$c2 = (int) $ticket->add(['name' => 'LABTEST - per event 2', 'content' => 'x', 'entities_id' => ENTITY, 'itilcategories_id' => $CAT_PE2]);
$created['Ticket'][] = $c2;
check('próximo chamado IT Support da entidade usa o Per-Event já aprovado', TicketState::contractOf($c2) === $pe_contract, json_encode(TicketState::get($c2)));

section('Ação automática: avisos 90/60/30 e renovação');
$today = '2026-11-16';
$renew = (int) $contract->add([
    'name' => 'LABTEST - Gestão vence', 'entities_id' => ENTITY, 'contracttypes_id' => 1, 'begin_date' => '2025-12-11', 'duration' => 12,
]);
$created['Contract'][] = $renew;   // fim calculado 2026-12-10: faltam 24 dias
Cron::run($today, [$renew]);
$sent = json_decode((string) ContractData::forContract($renew)['notices_sent'], true);
check('faltando 24 dias: marca 90/60/30 como enviados (um aviso só)', ($sent['2026-12-10'] ?? []) == [90, 60, 30], json_encode($sent));
$r1 = Cron::run($today, [$renew]);
check('rodar de novo no mesmo dia não reenvia', $r1['notices'] === 0, json_encode($r1));
Cron::run('2026-12-11', [$renew]);
check('passou da data final → renova por mais 12 meses', ContractData::endDate(Contract::getById($renew)->fields) === '2027-12-10', (string) ContractData::endDate(Contract::getById($renew)->fields));

$hrenew = (int) $contract->add([
    'name' => 'LABTEST - Horas renova', 'entities_id' => ENTITY, 'contracttypes_id' => 2, 'begin_date' => '2025-11-01', 'duration' => 12,
    '_clc' => ['franchise_hours' => '20'],
]);
$created['Contract'][] = $hrenew;
Settings::saveRow(Contract::class, $hrenew, ['auto_renewal' => 1]);
check('herança: contrato de banco de horas sobrescreve renovação automática', Settings::forContract($hrenew)['auto_renewal'] === 1 && Settings::forContract($hrenew)['min_business'] === 15);
Cron::run($today, [$hrenew]);
check('banco de horas com renovação: recarrega a franquia (20h + 20h)', Ledger::totals($hrenew)['balance'] === 40 * 3600, h(Ledger::totals($hrenew)['balance']));

// ------------------------------------------------------------------------------------------------
section('Limpeza');
foreach (array_reverse($created['Ticket']) as $id) {
    (new Ticket())->delete(['id' => $id], true);
}
foreach ($created['Contract'] as $id) {
    // trava: só apaga contrato da entidade de teste (nunca um contrato real do QA)
    $c = Contract::getById($id);
    if ($c && (int) $c->fields['entities_id'] === ENTITY) {
        $c->delete(['id' => $id], true);
    }
}
$rate_obj->delete(['id' => $rate_id], true);
$DB->delete(Focal::getTable(), ['entities_id' => ENTITY]);
foreach (array_keys(getSonsOf('glpi_itilcategories', $cloud)) + array_keys(getSonsOf('glpi_itilcategories', $itsupport)) as $id) {
    $category->delete(['id' => $id], true);
}
$DB->delete('glpi_itilcategories', ['entities_id' => ENTITY]);
$entity->delete(['id' => ENTITY], true);
check('sem resíduos no extrato', countElementsInTable(Ledger::getTable(), ['contracts_id' => $created['Contract']]) === 0);

echo "\n" . ($fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n");
exit($fails === 0 ? 0 : 1);
