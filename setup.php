<?php

/**
 * Contract Lifecycle — ciclo de vida de contratos (Sercompe)
 *
 * Tipos de contrato configuráveis (franquia ilimitada/limitada, renovação automática, cobrança Per-Event),
 * vínculo automático chamado → contrato pelo serviço (categoria), extrato de horas com cobrança mínima por
 * faixa de horário e saldos.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Contractlifecycle\Approval;
use GlpiPlugin\Contractlifecycle\Balance;
use GlpiPlugin\Contractlifecycle\ContractData;
use GlpiPlugin\Contractlifecycle\Focal;
use GlpiPlugin\Contractlifecycle\FormAction;
use GlpiPlugin\Contractlifecycle\FormRunner;
use GlpiPlugin\Contractlifecycle\Ledger;
use GlpiPlugin\Contractlifecycle\Menu;
use GlpiPlugin\Contractlifecycle\Profile as PluginProfile;
use GlpiPlugin\Contractlifecycle\Service;
use GlpiPlugin\Contractlifecycle\Settings;
use GlpiPlugin\Contractlifecycle\TicketState;

define('PLUGIN_CONTRACTLIFECYCLE_VERSION', '1.1.0');
define('PLUGIN_CONTRACTLIFECYCLE_MIN_GLPI', '11.0.0'); // inclusivo
define('PLUGIN_CONTRACTLIFECYCLE_MAX_GLPI', '12.1.0'); // exclusivo: aceita 11.0.x até 12.0.x

function plugin_init_contractlifecycle()
{
    global $PLUGIN_HOOKS;

    $plugin = new Plugin();
    if (!$plugin->isActivated('contractlifecycle')) {
        return;
    }

    // ---- hooks de dados: fora do bloco de sessão (valem para API, cron, ações em massa)
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['contractlifecycle'] = [
        Contract::class         => [ContractData::class, 'contractSaved'],
        Ticket::class           => [TicketState::class, 'ticketAdded'],
        TicketTask::class       => [Ledger::class, 'taskChanged'],
        TicketCost::class       => [Ledger::class, 'costChanged'],
        Ticket_Contract::class  => [TicketState::class, 'linkAdded'],
        // formulário enviado: o GLPI acabou de criar o chamado de destino
        \Glpi\Form\Destination\AnswersSet_FormDestinationItem::class => [FormRunner::class, 'destinationLinked'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['contractlifecycle'] = [
        Ticket::class           => [TicketState::class, 'ticketUpdated'],
        TicketTask::class       => [Ledger::class, 'taskChanged'],
        TicketCost::class       => [Ledger::class, 'costChanged'],
        TicketValidation::class => [Approval::class, 'validationUpdated'],
    ];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['contractlifecycle'] = [
        TicketTask::class => [Approval::class, 'preTaskAdd'],
    ];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['contractlifecycle'] = [
        Contract::class => [ContractData::class, 'contractPreUpdate'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['contractlifecycle'] = [
        Contract::class        => [ContractData::class, 'contractPurged'],
        Ticket::class          => [TicketState::class, 'ticketPurged'],
        TicketTask::class      => [Ledger::class, 'taskPurged'],
        TicketCost::class      => [Ledger::class, 'costPurged'],
        Ticket_Contract::class => [TicketState::class, 'linkPurged'],
        Entity::class          => [Focal::class, 'entityPurged'],
        ITILCategory::class    => [Service::class, 'categoryPurged'],
    ];

    Plugin::registerClass(Settings::class, ['addtabon' => [ContractType::class, Contract::class]]);
    Plugin::registerClass(Service::class, ['addtabon' => [Contract::class]]);
    Plugin::registerClass(Ledger::class, ['addtabon' => [Contract::class]]);
    Plugin::registerClass(Focal::class, ['addtabon' => [Entity::class]]);
    Plugin::registerClass(Balance::class, ['addtabon' => [Entity::class]]);
    Plugin::registerClass(FormAction::class, ['addtabon' => [\Glpi\Form\Form::class]]);
    Plugin::registerClass(PluginProfile::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(ContractData::class, ['notificationtemplates_types' => true]);

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['contractlifecycle']  = [ContractData::class, 'preItemForm'];
        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['contractlifecycle'] = [ContractData::class, 'postItemForm'];

        if (Session::haveRight(Settings::RIGHT, READ) || Session::haveRight(Ledger::RIGHT, READ)) {
            $PLUGIN_HOOKS[Hooks::MENU_TOADD]['contractlifecycle'] = ['management' => Menu::class];
        }
        if (Session::haveRight(Settings::RIGHT, READ)) {
            $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['contractlifecycle'] = 'front/config.php';
        }

        // ?m=<mtime>: o navegador não reaproveita CSS/JS antigo depois de uma atualização sem troca de versão
        $asset = fn (string $file) => $file . '?m=' . (int) @filemtime(__DIR__ . '/public/' . $file);
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['contractlifecycle']        = [$asset('contractlifecycle.css')];
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['contractlifecycle'] = [$asset('contractlifecycle.js')];

        // O menu fica em cache na sessão: invalida uma vez por versão do plugin
        if (($_SESSION['plugin_contractlifecycle_menu'] ?? null) !== PLUGIN_CONTRACTLIFECYCLE_VERSION) {
            unset($_SESSION['glpimenu']);
            $_SESSION['plugin_contractlifecycle_menu'] = PLUGIN_CONTRACTLIFECYCLE_VERSION;
        }
    }
}

function plugin_version_contractlifecycle()
{
    return [
        'name'         => __('Ciclo de vida de contratos', 'contractlifecycle'),
        'version'      => PLUGIN_CONTRACTLIFECYCLE_VERSION,
        'author'       => 'Pellissari',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://pellissari.com.br',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CONTRACTLIFECYCLE_MIN_GLPI,
                'max' => PLUGIN_CONTRACTLIFECYCLE_MAX_GLPI,
            ],
            'php'  => ['min' => '8.2'],
        ],
    ];
}
