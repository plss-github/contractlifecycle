<?php

/**
 * Regressão do vínculo chamado ↔ contrato (bugs do teste de 22/11/2026):
 *  - contrato padrão da entidade ("Contrato na entidade do chamado") vinculava contrato sem serviço algum;
 *  - regra de negócio "atribuir contrato" + vínculo já existente → Duplicate entry em glpi_tickets_contracts;
 *  - "Incluir subcategorias" com o campo vazio adicionava o catálogo inteiro ao contrato.
 *   docker compose exec -T glpi php /var/www/glpi/plugins/contractlifecycle/tests/lab/links.php
 * Cria a entidade LABLINKS e apaga tudo no fim.
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Contractlifecycle\Helpers;
use GlpiPlugin\Contractlifecycle\Service;
use GlpiPlugin\Contractlifecycle\TicketState;

$auth = new Auth();
$auth->user = new User();
$auth->user->getFromDBbyName('glpi');
$auth->auth_succeded = true;
Session::init($auth);
Session::changeActiveEntities(0, true);

global $DB;
$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    $fails += $ok ? 0 : 1;
}
/** @return string[] nomes dos contratos vinculados (ordenados) */
function linked(int $tickets_id): array
{
    global $DB;
    $names = [];
    foreach ($DB->request(['FROM' => 'glpi_tickets_contracts', 'WHERE' => ['tickets_id' => $tickets_id]]) as $row) {
        $names[] = Contract::getById((int) $row['contracts_id'])->getName();
    }
    sort($names);
    return $names;
}
function cleanup(): void
{
    global $DB;
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_rules', 'WHERE' => ['name' => ['LIKE', 'LABLINKS%']]]) as $row) {
        (new RuleTicket())->delete(['id' => $row['id']], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', 'LABLINKS%']]]) as $row) {
        (new Ticket())->delete(['id' => $row['id']], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_contracts', 'WHERE' => ['name' => ['LIKE', 'LABLINKS%']]]) as $row) {
        (new Contract())->delete(['id' => $row['id']], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['name' => ['LIKE', 'LABLINKS%']], 'ORDER' => 'level DESC']) as $row) {
        (new ITILCategory())->delete(['id' => $row['id']], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_entities', 'WHERE' => ['name' => 'LABLINKS']]) as $row) {
        (new Entity())->delete(['id' => $row['id']], true);
    }
}
cleanup();
register_shutdown_function('cleanup');

// entidade com "Contrato padrão = contrato na entidade do chamado"
$ent  = (new Entity())->add(['name' => 'LABLINKS', 'entities_id' => 0]);
(new Entity())->update(['id' => $ent, 'contracts_strategy_default' => Entity::CONFIG_AUTO]);
$cat1 = (new ITILCategory())->add(['name' => 'LABLINKS Cat1', 'entities_id' => $ent]);
$cat2 = (new ITILCategory())->add(['name' => 'LABLINKS Cat2', 'entities_id' => $ent]);
$sub2 = (new ITILCategory())->add(['name' => 'LABLINKS Sub2', 'itilcategories_id' => $cat2, 'entities_id' => $ent]);
$cat3 = (new ITILCategory())->add(['name' => 'LABLINKS Cat3', 'entities_id' => $ent]);
$dates = ['begin_date' => Helpers::today(), 'duration' => 12, 'entities_id' => $ent];
$c1 = (new Contract())->add(['name' => 'LABLINKS C1 sem serviços'] + $dates);
$c2 = (new Contract())->add(['name' => 'LABLINKS C2'] + $dates);
$c3 = (new Contract())->add(['name' => 'LABLINKS C3'] + $dates);
Service::addCategory($c2, $cat2);
Service::addCategory($c3, $cat3);

echo "Serviços do contrato\n";
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
// o select múltiplo do GLPI posta itilcategories_id="" quando nada foi escolhido
Service::handlePost(['contracts_id' => $c1, 'add' => 1, 'itilcategories_id' => '', 'with_children' => 1]);
check('campo vazio + subcategorias não adiciona nada', Service::categoriesOf($c1) === [], json_encode(Service::categoriesOf($c1)));
Service::handlePost(['contracts_id' => $c1, 'add' => 1, 'itilcategories_id' => ['', (string) $cat2], 'with_children' => 1]);
$got = Service::categoriesOf($c1);
sort($got);
check('Cat2 + subcategorias adiciona só Cat2 e Sub2', $got === [$cat2, $sub2], json_encode($got));
$DB->delete('glpi_plugin_contractlifecycle_services', ['contracts_id' => $c1]);

echo "Contrato padrão da entidade\n";
$t = new Ticket();
$tid = $t->add(['name' => 'LABLINKS 1', 'content' => 'x', 'entities_id' => $ent]);
check('chamado sem categoria não herda contrato sem serviço', linked($tid) === [], json_encode(linked($tid)));
$t->update(['id' => $tid, 'itilcategories_id' => $cat2]);
check('Cat2 → C2', linked($tid) === ['LABLINKS C2'] && TicketState::contractOf($tid) === $c2, json_encode(linked($tid)));
$t->update(['id' => $tid, 'itilcategories_id' => $cat1]);
check('Cat1 (nenhum contrato) → sem vínculo', linked($tid) === [] && TicketState::contractOf($tid) === 0, json_encode(linked($tid)));
$t->update(['id' => $tid, 'itilcategories_id' => $cat3]);
$t->update(['id' => $tid, 'itilcategories_id' => $cat2]);
check('Cat3 → Cat2: um contrato só', linked($tid) === ['LABLINKS C2'], json_encode(linked($tid)));

$tid2 = $t->add(['name' => 'LABLINKS 2', 'content' => 'x', 'entities_id' => $ent, '_contracts_id' => $c1]);
check('contrato escolhido no formulário continua valendo', linked($tid2) === ['LABLINKS C1 sem serviços'], json_encode(linked($tid2)));

echo "Regra de negócio atribuindo contrato já vinculado\n";
$rule = new RuleTicket();
$rid = $rule->add(['name' => 'LABLINKS regra', 'sub_type' => 'RuleTicket', 'match' => 'AND', 'is_active' => 1,
    'condition' => RuleTicket::ONADD | RuleTicket::ONUPDATE, 'entities_id' => 0, 'is_recursive' => 1]);
(new RuleCriteria())->add(['rules_id' => $rid, 'criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_IS, 'pattern' => $cat2]);
(new RuleAction())->add(['rules_id' => $rid, 'action_type' => 'assign', 'field' => 'assign_contract', 'value' => $c2]);
$errors = [];
foreach ([$cat1, $cat2, $cat3, $cat2, $cat1, $cat2] as $cat) {
    try {
        if (!$t->update(['id' => $tid, 'itilcategories_id' => $cat])) {
            $errors[] = "update cat $cat falhou";
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
check('circular entre categorias não gera erro SQL', $errors === [], implode(' | ', $errors));
check('termina com C2 vinculado uma vez', linked($tid) === ['LABLINKS C2'], json_encode(linked($tid)));

echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
