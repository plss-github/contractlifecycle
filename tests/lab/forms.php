<?php

/**
 * Harness da automação de formulários, com envios reais dos formulários do QA sincronizados no laboratório
 * (26 Onboard, 29 Recarga, 28 Renovação, 30 Não renovação ou Cancelamento).
 *   docker compose exec -T glpi php /var/www/glpi/plugins/contractlifecycle/tests/lab/forms.php
 * Restaura a configuração dos formulários e apaga tudo o que criar (cliente "LABTEST ONBOARD").
 */

ob_start();
register_shutdown_function(fn () => ob_get_level() && ob_end_flush());
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel('production');
$kernel->boot();
// como num envio pela tela: há uma requisição HTTP principal (o core consulta isAPI() ao criar usuários)
$GLOBALS['kernel'] = $kernel;
$kernel->getContainer()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create('/front/central.php'));

use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Form;
use GlpiPlugin\Contractlifecycle\ContractData;
use GlpiPlugin\Contractlifecycle\Focal;
use GlpiPlugin\Contractlifecycle\FormAction;
use GlpiPlugin\Contractlifecycle\Ledger;
use GlpiPlugin\Contractlifecycle\Service;
use GlpiPlugin\Contractlifecycle\Settings;

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
function section(string $title): void
{
    echo "\n== $title\n";
}

const CLIENT = 'LABTEST ONBOARD';
const FORMS = ['onboard' => 26, 'recharge' => 29, 'renewal' => 28, 'termination' => 30];

/** Chave da opção pelo rótulo (o teste não depende das chaves internas). */
function optionKey(int $question_id, string $label): string
{
    global $DB;
    $extra = json_decode((string) $DB->request(['SELECT' => 'extra_data', 'FROM' => 'glpi_forms_questions', 'WHERE' => ['id' => $question_id]])->current()['extra_data'], true);
    foreach ($extra['options'] ?? [] as $key => $text) {
        if (FormAction::norm($text) === FormAction::norm($label)) {
            return (string) $key;
        }
    }
    throw new RuntimeException("opção '$label' não existe na pergunta $question_id");
}

/** Pergunta pelo nome (e seção), para o teste não depender de IDs. */
function qid(int $forms_id, string $name, ?string $section = null): int
{
    foreach (FormAction::questions($forms_id) as $q) {
        if (FormAction::norm($q['name']) === FormAction::norm($name) && ($section === null || FormAction::norm($q['section']) === FormAction::norm($section))) {
            return (int) $q['id'];
        }
    }
    throw new RuntimeException("pergunta '$name' não existe no formulário $forms_id");
}

function submit(int $forms_id, array $answers): ?Ticket
{
    $form = Form::getById($forms_id);
    $set = AnswersHandler::getInstance()->saveAnswers($form, $answers, (int) Session::getLoginUserID());
    $link = new \Glpi\Form\Destination\AnswersSet_FormDestinationItem();
    if ($link->getFromDBByCrit(['forms_answerssets_id' => $set->getID(), 'itemtype' => Ticket::class])) {
        return Ticket::getById((int) $link->fields['items_id']) ?: null;
    }
    return null;
}

function lastRun(int $forms_id): array
{
    global $DB;
    return $DB->request(['FROM' => 'glpi_plugin_contractlifecycle_formruns', 'WHERE' => ['forms_forms_id' => $forms_id], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current() ?? [];
}

function cleanup(): void
{
    global $DB;
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_entities', 'WHERE' => ['name' => CLIENT]]) as $e) {
        $ids = [(int) $e['id']];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_contracts', 'WHERE' => ['entities_id' => $ids]]) as $c) {
            (new Contract())->delete(['id' => $c['id']], true);
        }
        foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => Focal::getTable(), 'WHERE' => ['entities_id' => $ids]]) as $f) {
            $u = User::getById((int) $f['users_id']);
            if ($u && str_ends_with($u->fields['name'], '@labtest.local')) {
                $u->delete(['id' => $u->getID()], true);
            }
        }
        $DB->delete(Focal::getTable(), ['entities_id' => $ids]);
        $DB->delete('glpi_itilcategories', ['entities_id' => $ids]);
        (new Entity())->delete(['id' => $e['id']], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', '%@labtest.local']]]) as $u) {
        (new User())->delete(['id' => $u['id']], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['content' => ['LIKE', '%LABTEST%']]]) as $t) {
        (new Ticket())->delete(['id' => $t['id']], true);
    }
}

// estado anterior (restaurado no fim)
$saved_actions = iterator_to_array($DB->request(['FROM' => FormAction::getTable(), 'WHERE' => ['forms_forms_id' => array_values(FORMS)]]), false);
$runs_before = (int) $DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => 'glpi_plugin_contractlifecycle_formruns'])->current()['m'];
cleanup();
register_shutdown_function(function () use ($saved_actions, $runs_before) {
    global $DB;
    cleanup();
    $DB->delete(FormAction::getTable(), ['forms_forms_id' => array_values(FORMS)]);
    foreach ($saved_actions as $row) {
        $DB->insert(FormAction::getTable(), $row);
    }
    $DB->delete('glpi_plugin_contractlifecycle_formruns', ['id' => ['>', $runs_before]]);
});

// ------------------------------------------------------------------------------------------------
section('Mapeamento automático nos formulários do QA');
foreach (FORMS as $action => $forms_id) {
    [$mapping] = FormAction::autoMap($forms_id, $action, []);
    FormAction::save($forms_id, ['action' => $action, 'is_active' => 1, 'mapping' => $mapping]);
    $questions = FormAction::questions($forms_id);
    $missing = [];
    foreach (FormAction::fields($action) as $key => $field) {
        [, $status] = FormAction::resolve($mapping[$key] ?? null, $questions);
        if ($status !== FormAction::STATUS_OK && ($field['required'] || $action === 'onboard' && $key !== 'notify_email')) {
            $missing[] = $key;
        }
    }
    // o formulário 30 ainda não tem a pergunta de data (opcional): esperado
    check("formulário $forms_id ($action): campos mapeados pelos nomes", $missing === [], implode(', ', $missing));
}
$sections = FormAction::forForm(26)['mapping']['_sections'];
$areas = array_column($sections, 'area', 'name');
check('seções de serviço → área do catálogo (Cloud Backup → Cloud, Edge Firewall → Edge, IT Support, IT Care)',
    ($areas['Cloud Backup'] ?? '') === 'Cloud' && ($areas['Edge Firewall'] ?? '') === 'Edge'
    && ($areas['IT Support'] ?? '') === 'IT Support' && ($areas['IT Care'] ?? '') === 'IT Care' && ($areas['Master'] ?? 'x') === '', json_encode($areas, JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------------------------------------------
section('Onboard: entidade, catálogo, contratos, usuários e pontos focais');
$F = 26;
$answers = [
    qid($F, 'Cliente') => CLIENT,
    qid($F, 'CNPJ') => '12.345.678/0001-99',
    qid($F, 'Inscrição Estadual') => '123.456',
    qid($F, 'Rua') => 'Rua do Teste, 100',
    qid($F, 'CEP') => '89000-000',
    qid($F, 'Cidade') => 'Blumenau',
    qid($F, 'País') => 'Brasil',
    qid($F, 'Incio de contrato') => '2026-11-01',
    qid($F, 'Fim de contrato') => '2027-10-31',
    qid($F, 'Número da oportunidade comercial') => 'OP-999',
    qid($F, 'Responsável Comercial') => 'Vendedor Teste',
    qid($F, 'Email Resp. Comercial') => 'vendas@labtest.local',
    qid($F, 'Email secundário para envio de notificação do processo de onboard') => 'acompanha@labtest.local',
    qid($F, 'Informe quais usuários serão criados') => [optionKey(qid($F, 'Informe quais usuários serão criados'), 'Master'), optionKey(qid($F, 'Informe quais usuários serão criados'), 'Financeiro')],
    qid($F, 'Nome', 'Master') => 'Maria', qid($F, 'Sobrenome', 'Master') => 'Master', qid($F, 'E-mail', 'Master') => 'maria@labtest.local', qid($F, 'Telefone', 'Master') => '47 9999-0001',
    qid($F, 'Nome', 'Financeiro') => 'Fabio', qid($F, 'Sobrenome', 'Financeiro') => 'Financeiro', qid($F, 'E-mail', 'Financeiro') => 'fabio@labtest.local', qid($F, 'Telefone', 'Financeiro') => '47 9999-0002',
    qid($F, 'Banco de Dados', 'IT Support') => [optionKey(qid($F, 'Banco de Dados', 'IT Support'), 'MySQL'), optionKey(qid($F, 'Banco de Dados', 'IT Support'), 'Oracle')],
    qid($F, 'Sistema Operacional', 'IT Support') => [optionKey(qid($F, 'Sistema Operacional', 'IT Support'), 'Linux/Unix')],
    qid($F, 'Proteção de Dados', 'Cloud Backup') => [optionKey(qid($F, 'Proteção de Dados', 'Cloud Backup'), 'Backup')],
];
$ticket = submit($F, $answers);
check('envio do formulário criou o chamado', $ticket !== null);
$ticket->update(['id' => $ticket->getID(), 'content' => 'LABTEST onboard']);
$run = lastRun($F);
check('execução registrada sem erros', ($run['status'] ?? '') === 'ok', ($run['status'] ?? '?') . "\n" . ($run['report'] ?? ''));

$entity = new Entity();
check('entidade do cliente criada sob "Clientes" com CNPJ e endereço', $entity->getFromDBByCrit(['name' => CLIENT, 'entities_id' => 1])
    && $entity->fields['registration_number'] === '12.345.678/0001-99' && $entity->fields['town'] === 'Blumenau');
$eid = (int) $entity->getID();
$cats = array_column(iterator_to_array($DB->request(['SELECT' => 'completename', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['entities_id' => $eid]]), false), 'completename');
sort($cats);
$expected = ['Cloud', 'Cloud > Proteção de Dados', 'Cloud > Proteção de Dados > Backup', 'IT Support', 'IT Support > Banco de Dados',
    'IT Support > Banco de Dados > MySQL', 'IT Support > Banco de Dados > Oracle', 'IT Support > Sistema Operacional', 'IT Support > Sistema Operacional > Linux/Unix'];
check('catálogo copiado só com os ramos marcados', $cats === $expected, json_encode($cats, JSON_UNESCAPED_UNICODE));

$contracts = iterator_to_array($DB->request(['FROM' => 'glpi_contracts', 'WHERE' => ['entities_id' => $eid], 'ORDER' => 'contracttypes_id']), false);
$types = array_map(fn ($c) => \Dropdown::getDropdownName('glpi_contracttypes', $c['contracttypes_id']), $contracts);
check('dois contratos: Gestão (Cloud) e Banco de Horas (IT Support)', $types === ['Gestão', 'Banco de Horas'], json_encode($types, JSON_UNESCAPED_UNICODE));
$gestao = (int) ($contracts[0]['id'] ?? 0);
$horas = (int) ($contracts[1]['id'] ?? 0);
check('serviços: Gestão com 3 categorias de Cloud, Banco de Horas com 6 de IT Support', count(Service::categoriesOf($gestao)) === 3 && count(Service::categoriesOf($horas)) === 6);
$data = ContractData::forContract($horas);
check('contrato aguardando ativação, com vigência planejada', (int) $data['pending_activation'] === 1 && $data['planned_begin'] === '2026-11-01' && $data['planned_end'] === '2027-10-31', json_encode($data));
check('contrato pendente não é vigente (não pega chamados)', !ContractData::isActiveOn(Contract::getById($horas)->fields, '2026-11-16'));

$master = new User();
check('usuário Master criado sem senha, perfil Master na entidade', $master->getFromDBbyName('maria@labtest.local') && empty($master->fields['password'])
    && (new Profile_User())->getFromDBByCrit(['users_id' => $master->getID(), 'entities_id' => $eid, 'profiles_id' => 12]));
check('usuário Técnico não criado (não marcado)', !(new User())->getFromDBbyName('tecnico@labtest.local'));
$focals = Focal::ofEntity($eid);
check('pontos focais: Master → contrato, Financeiro → financeiro', count($focals['contract']) === 1 && count($focals['financial']) === 1 && $focals['technical'] === [], json_encode($focals));
check('e-mails de acompanhamento como observadores do chamado', countElementsInTable('glpi_tickets_users', ['tickets_id' => $ticket->getID(), 'type' => CommonITILActor::OBSERVER, 'alternative_email' => ['acompanha@labtest.local', 'vendas@labtest.local']]) === 2);
check('relatório em acompanhamento privado do chamado', countElementsInTable('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticket->getID(), 'is_private' => 1]) >= 1);

section('Fechamento do chamado ativa os contratos');
// a regra do QA "STATUS - Novo" devolve o status para Novo enquanto não há técnico: atribui antes de fechar
$ticket->update(['id' => $ticket->getID(), '_actors' => ['assign' => [['itemtype' => 'User', 'items_id' => 10, 'use_notification' => 0]]]]);
$ticket->update(['id' => $ticket->getID(), 'status' => Ticket::CLOSED, 'closedate' => '2026-11-20 10:00:00']);
$ticket->getFromDB($ticket->getID());
check('chamado fechado (com técnico atribuído)', (int) $ticket->fields['status'] === Ticket::CLOSED, 'status=' . $ticket->fields['status']);
$c = Contract::getById($horas);
$data = ContractData::forContract($horas);
$closed = substr((string) $ticket->fields['closedate'], 0, 10);
$exp_end = date('Y-m-d', strtotime($closed . ' +364 days'));
check('ativado: início = data do fechamento, fim = início + duração planejada (364 dias)', (int) $data['pending_activation'] === 0 && $c->fields['begin_date'] === $closed && $data['end_date'] === $exp_end, $c->fields['begin_date'] . ' / ' . $data['end_date']);
check('contrato vigente após ativar', ContractData::isActiveOn($c->fields, $closed));

// ------------------------------------------------------------------------------------------------
section('Recarga, renovação, não renovação e cancelamento');
Settings::saveRow(Contract::class, $horas, ['auto_renewal' => 1]);
$t = submit(29, [qid(29, 'Informe o contrato') => ['itemtype' => 'Contract', 'items_id' => $horas], qid(29, 'Quantidade de horas') => '10']);
$t?->update(['id' => $t->getID(), 'content' => 'LABTEST recarga']);
check('recarga de 10h lançada no extrato', Ledger::totals($horas)['credits'] === 36000, json_encode(Ledger::totals($horas)) . "\n" . (lastRun(29)['report'] ?? ''));

$t = submit(28, [qid(28, 'Informe o contrato') => ['itemtype' => 'Contract', 'items_id' => $horas]]);
$t?->update(['id' => $t->getID(), 'content' => 'LABTEST renovação']);
$renewed = date('Y-m-d', strtotime($exp_end . ' +12 months'));
check('renovação estende a vigência pela duração do contrato (12 meses)', ContractData::forContract($horas)['end_date'] === $renewed, (string) ContractData::forContract($horas)['end_date']);

$opt = qid(30, 'Escolha uma opção');
$t = submit(30, [$opt => optionKey($opt, 'Não renovação'), qid(30, 'Informe o contrato') => ['itemtype' => 'Contract', 'items_id' => $horas], qid(30, 'Motivo') => 'Teste de não renovação']);
$t?->update(['id' => $t->getID(), 'content' => 'LABTEST não renovação']);
Settings::clearCache();
check('não renovação: desliga a renovação automática e mantém a data final', Settings::forContract($horas)['auto_renewal'] === 0 && ContractData::forContract($horas)['end_date'] === $renewed,
    json_encode(['auto' => Settings::forContract($horas)['auto_renewal'], 'end' => ContractData::forContract($horas)['end_date']]));
check('motivo registrado no comentário do contrato', str_contains((string) Contract::getById($horas)->fields['comment'], 'Teste de não renovação'));

$t = submit(30, [$opt => optionKey($opt, 'Cancelamento'), qid(30, 'Informe o contrato') => ['itemtype' => 'Contract', 'items_id' => $gestao], qid(30, 'Motivo') => 'Teste de cancelamento']);
$t?->update(['id' => $t->getID(), 'content' => 'LABTEST cancelamento']);
$set_date = substr((string) $DB->request(['SELECT' => 'date_creation', 'FROM' => 'glpi_forms_answerssets', 'ORDER' => 'id DESC', 'LIMIT' => 1])->current()['date_creation'], 0, 10);
check('cancelamento: encerra 30 dias após a solicitação (sem campo de data no formulário)', ContractData::forContract($gestao)['end_date'] === date('Y-m-d', strtotime($set_date . ' +30 days')), (string) ContractData::forContract($gestao)['end_date']);

// ------------------------------------------------------------------------------------------------
section('Formulário mudou: reencontro pelo nome e falha sem quebrar o envio');
$cfg = FormAction::forForm(29);
$cfg['mapping']['hours']['uuid'] = 'uuid-de-outro-ambiente';
FormAction::save(29, ['mapping' => $cfg['mapping']]);
[, $status] = FormAction::resolve(FormAction::forForm(29)['mapping']['hours'], FormAction::questions(29));
check('UUID não existe mais → pergunta reencontrada pelo nome', $status === FormAction::STATUS_RENAMED, $status);
$before = Ledger::totals($horas)['credits'];
$t = submit(29, [qid(29, 'Informe o contrato') => ['itemtype' => 'Contract', 'items_id' => $horas], qid(29, 'Quantidade de horas') => '5']);
$t?->update(['id' => $t->getID(), 'content' => 'LABTEST recarga 2']);
check('recarga funciona com a pergunta reencontrada', Ledger::totals($horas)['credits'] === $before + 18000);

$cfg['mapping']['hours'] = ['uuid' => 'sumiu', 'name' => 'Pergunta apagada', 'section' => ''];
FormAction::save(29, ['mapping' => $cfg['mapping']]);
$t = submit(29, [qid(29, 'Informe o contrato') => ['itemtype' => 'Contract', 'items_id' => $horas], qid(29, 'Quantidade de horas') => '5']);
check('pergunta sumiu: o envio continua e o chamado é criado', $t !== null);
$t?->update(['id' => $t->getID(), 'content' => 'LABTEST recarga 3']);
check('execução registrada como erro, com o motivo, e nada lançado', (lastRun(29)['status'] ?? '') === 'error' && Ledger::totals($horas)['credits'] === $before + 18000, (lastRun(29)['report'] ?? ''));
[$fixed, $changed] = FormAction::autoMap(29, 'recharge', $cfg['mapping']);
check('"Mapear automaticamente" recupera o campo pelo nome', $changed >= 1 && FormAction::resolve($fixed['hours'], FormAction::questions(29))[1] === FormAction::STATUS_OK);

$total = $fails;
echo "\n" . ($total === 0 ? "ALL PASSED\n" : "$total FAILED\n");
