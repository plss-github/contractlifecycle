<?php

/**
 * Dados de demonstração no laboratório (idempotente; refazer: rodar de novo).
 *   docker compose exec -T glpi php /var/www/glpi/plugins/contractlifecycle/tests/lab/demo.php
 *
 * Pamplona (entidade 3):
 *  - contrato 1 "PAMPLONA - 0446" → Banco de Horas, 40h, serviços = os do Fields (Oracle, Servidor, Linux)
 *  - contrato 5 "PAMPLONA - 304-2026" → Gestão, serviços = árvore IT Support da Pamplona (como a regra do QA)
 *  - ponto focal (contrato) = user.gerente
 *  - chamados LABDEMO com tarefas em várias faixas
 * Advice Health (entidade 12): chamado IT Support sem contrato → aprovação Per-Event pendente.
 */

ob_start();
register_shutdown_function(fn () => ob_get_level() && ob_end_flush());
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Contractlifecycle\ContractData;
use GlpiPlugin\Contractlifecycle\Focal;
use GlpiPlugin\Contractlifecycle\Rate;
use GlpiPlugin\Contractlifecycle\Service;

$auth = new Auth();
$auth->user = new User();
$auth->user->getFromDBbyName('glpi');
$auth->auth_succeded = true;
Session::init($auth);
Session::changeActiveEntities(0, true);

global $DB;

// limpa a demo anterior
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', 'LABDEMO%']]]) as $row) {
    (new Ticket())->delete(['id' => $row['id']], true);
}
foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_contracts', 'WHERE' => ['comment' => ['LIKE', '%Per-Event do chamado%']]]) as $row) {
    (new Contract())->delete(['id' => $row['id']], true);
}
$DB->delete('glpi_plugin_contractlifecycle_ledgers', ['contracts_id' => [1, 5]]);
$DB->delete('glpi_plugin_contractlifecycle_services', ['contracts_id' => [1, 5]]);
$DB->delete('glpi_plugin_contractlifecycle_contractdatas', ['contracts_id' => [1, 5]]);
$DB->delete('glpi_plugin_contractlifecycle_settings', ['itemtype' => 'Contract', 'items_id' => [1, 5]]);

// tabela Per-Event
$rate = new Rate();
if (!$rate->getFromDBByCrit(['name' => 'Per-Event 2026'])) {
    $rate->add(['name' => 'Per-Event 2026', 'valid_from' => '2026-01-01', 'hourly_value' => '250', 'mult_business' => 1, 'mult_offhours' => 1.5, 'mult_weekend' => 2,
        'comment' => 'Valores do ano fiscal 2026 (demonstração).']);
}

// contratos da Pamplona
$contract = new Contract();
$contract->update(['id' => 1, 'contracttypes_id' => 2, 'begin_date' => '2026-01-01', 'duration' => 12, '_clc' => ['franchise_hours' => '40']]);
foreach (json_decode((string) $DB->request(['SELECT' => 'itilcategories_id_serviofield', 'FROM' => 'glpi_plugin_fields_contractics', 'WHERE' => ['items_id' => 1]])->current()['itilcategories_id_serviofield'], true) ?? [] as $cat) {
    Service::addCategory(1, (int) $cat);
}
$contract->update(['id' => 5, 'contracttypes_id' => 1, 'begin_date' => '2026-01-01', 'duration' => 12]);
Service::addCategory(5, 417);
foreach (array_keys(getSonsOf('glpi_itilcategories', 417)) as $cat) {
    Service::addCategory(5, (int) $cat);
}

$DB->delete(Focal::getTable(), ['entities_id' => [3, 12]]);
$DB->insert(Focal::getTable(), ['entities_id' => 3, 'role' => 'contract', 'users_id' => 11]);
$DB->insert(Focal::getTable(), ['entities_id' => 12, 'role' => 'contract', 'users_id' => 11]);

// chamados
$ticket = new Ticket();
$task = new TicketTask();
$mk = function (string $name, int $entity, int $category, array $tasks) use ($ticket, $task): int {
    $id = (int) $ticket->add(['name' => "LABDEMO - $name", 'content' => 'Chamado de demonstração do ciclo de vida de contratos.', 'entities_id' => $entity, 'itilcategories_id' => $category]);
    foreach ($tasks as [$date, $minutes, $text, $tech]) {
        $task->add(['tickets_id' => $id, 'date' => $date, 'actiontime' => $minutes * 60, 'content' => $text, 'users_id_tech' => $tech]);
    }
    return $id;
};
$mk('Lentidão no Oracle', 3, 38, [
    ['2026-11-03 09:10:00', 5, 'Análise inicial dos waits do banco', 10],
    ['2026-11-03 14:00:00', 95, 'Ajuste de índices e estatísticas', 10],
    ['2026-11-04 19:30:00', 25, 'Acompanhamento fora do horário', 18],
]);
$mk('Servidor de aplicação reiniciando', 3, 40, [
    ['2026-11-07 10:00:00', 30, 'Coleta de logs (sábado)', 18],
    ['2026-11-10 17:50:00', 15, 'Reinício controlado (cruza 18h)', 10],
]);
$mk('Atualização do Linux', 3, 42, [
    ['2026-11-12 08:30:00', 240, 'Janela de atualização de pacotes', 20],
]);
$it = (int) $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['entities_id' => 3, 'completename' => 'IT Support > Sistema Operacional']])->current()['id'];
$mk('Dúvida sobre permissões no Windows', 3, $it, [
    ['2026-11-11 11:00:00', 20, 'Orientação ao usuário', 10],
]);
$it4 = (int) ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['entities_id' => 12, 'completename' => ['LIKE', 'IT Support >%']], 'LIMIT' => 1])->current()['id'] ?? 0);
if ($it4) {
    $mk('Instalação de software (sem contrato)', 12, $it4, []);
}

// automação dos formulários do QA (Onboard, Renovação, Recarga, Não renovação/Cancelamento), se ainda não configurada
foreach (['onboard' => 26, 'renewal' => 28, 'recharge' => 29, 'termination' => 30] as $action => $forms_id) {
    if (\Glpi\Form\Form::getById($forms_id) && \GlpiPlugin\Contractlifecycle\FormAction::forForm($forms_id) === null) {
        [$mapping] = \GlpiPlugin\Contractlifecycle\FormAction::autoMap($forms_id, $action, []);
        \GlpiPlugin\Contractlifecycle\FormAction::save($forms_id, ['action' => $action, 'is_active' => 1, 'mapping' => $mapping]);
    }
}

echo "Demo pronta.\n";
foreach ([1, 5] as $id) {
    $t = \GlpiPlugin\Contractlifecycle\Ledger::totals($id);
    printf("  contrato %d: entradas %s, debitado %s, saldo %s\n", $id, \GlpiPlugin\Contractlifecycle\Helpers::hours($t['credits']), \GlpiPlugin\Contractlifecycle\Helpers::hours($t['debited']), \GlpiPlugin\Contractlifecycle\Helpers::hours($t['balance']));
}
