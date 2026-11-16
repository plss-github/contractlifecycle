<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use CommonGLPI;
use Glpi\Form\Form;
use Session;

/**
 * Liga um formulário nativo (GLPI 11) a uma ação do ciclo de vida e diz qual pergunta alimenta cada dado.
 *
 * Resistente a mudanças no formulário: cada campo guarda o UUID da pergunta (estável quando ela é editada) e
 * também a seção/nome. Se o UUID sumir (pergunta recriada, formulário importado de outro ambiente), a pergunta é
 * reencontrada pelo nome e o mapeamento se corrige sozinho. A aba mostra o estado de cada campo.
 */
class FormAction extends CommonDBTM
{
    public static $rightname = Settings::RIGHT;

    public const STATUS_OK       = 'ok';
    public const STATUS_RENAMED  = 'renamed';
    public const STATUS_MISSING  = 'missing';
    public const STATUS_UNMAPPED = 'unmapped';

    public static function getTypeName($nb = 0)
    {
        return __('Ciclo de vida', 'contractlifecycle');
    }

    public static function actions(): array
    {
        return [
            'onboard'     => __('Onboard (cliente, catálogo, contrato e usuários)', 'contractlifecycle'),
            'recharge'    => __('Recarga de horas', 'contractlifecycle'),
            'renewal'     => __('Renovação de contrato', 'contractlifecycle'),
            'termination' => __('Não renovação ou cancelamento', 'contractlifecycle'),
        ];
    }

    public static function groupLabels(): array
    {
        return [
            'client'   => __('Cliente', 'contractlifecycle'),
            'contract' => __('Contrato', 'contractlifecycle'),
            'users'    => __('Usuários', 'contractlifecycle'),
            'services' => __('Serviços', 'contractlifecycle'),
            'main'     => __('Perguntas', 'contractlifecycle'),
        ];
    }

    /**
     * Campos de cada ação.
     * hints: nomes prováveis da pergunta para o mapeamento automático ([seção, nome] ou só nome);
     * types: tipos de pergunta aceitos (sufixo da classe QuestionType...).
     */
    public static function fields(string $action): array
    {
        $text = ['ShortText', 'LongText', 'Email'];
        $f = fn (string $group, string $label, array $hints, array $types, bool $required = false) =>
            compact('group', 'label', 'hints', 'types', 'required');

        $user = function (string $role, string $section) use ($f, $text): array {
            return [
                "{$role}_firstname" => $f('users', sprintf('%s: %s', $section, __('Nome', 'contractlifecycle')), [[$section, 'Nome']], $text),
                "{$role}_lastname"  => $f('users', sprintf('%s: %s', $section, __('Sobrenome', 'contractlifecycle')), [[$section, 'Sobrenome']], $text),
                "{$role}_email"     => $f('users', sprintf('%s: %s', $section, __('E-mail', 'contractlifecycle')), [[$section, 'E-mail'], [$section, 'Email']], $text),
                "{$role}_phone"     => $f('users', sprintf('%s: %s', $section, __('Telefone', 'contractlifecycle')), [[$section, 'Telefone']], $text),
            ];
        };

        return match ($action) {
            'onboard' => [
                'client'          => $f('client', __('Nome do cliente (entidade)', 'contractlifecycle'), ['Cliente', 'Nome do cliente', 'Razão social'], $text, true),
                'cnpj'            => $f('client', 'CNPJ', ['CNPJ'], $text),
                'ie'              => $f('client', __('Inscrição estadual', 'contractlifecycle'), ['Inscrição Estadual'], $text),
                'street'          => $f('client', __('Endereço', 'contractlifecycle'), ['Rua', 'Endereço'], $text),
                'postcode'        => $f('client', 'CEP', ['CEP'], $text),
                'town'            => $f('client', __('Cidade', 'contractlifecycle'), ['Cidade'], $text),
                'country'         => $f('client', __('País', 'contractlifecycle'), ['País'], $text),
                'begin'           => $f('contract', __('Início do contrato', 'contractlifecycle'), ['Início de contrato', 'Incio de contrato', 'Início do contrato'], ['DateTime']),
                'end'             => $f('contract', __('Fim do contrato', 'contractlifecycle'), ['Fim de contrato', 'Fim do contrato'], ['DateTime']),
                'opportunity'     => $f('contract', __('Número da oportunidade', 'contractlifecycle'), ['Número da oportunidade comercial', 'Número da oportunidade'], $text),
                'sales_name'      => $f('contract', __('Responsável comercial', 'contractlifecycle'), ['Responsável Comercial'], $text),
                'sales_email'     => $f('contract', __('E-mail do responsável comercial', 'contractlifecycle'), ['Email Resp. Comercial', 'E-mail Resp. Comercial'], $text),
                'notify_email'    => $f('contract', __('E-mail para acompanhar o onboard', 'contractlifecycle'), ['Email secundário para envio de notificação do processo de onboard', 'Email secundário'], $text),
                'proposals'       => $f('contract', __('Propostas (anexos)', 'contractlifecycle'), ['Inserir as propostas', 'Propostas'], ['File']),
                'users_choice'    => $f('users', __('Quais usuários criar', 'contractlifecycle'), ['Informe quais usuários serão criados'], ['Checkbox']),
            ] + $user('contract', 'Master') + $user('technical', 'Técnico') + $user('financial', 'Financeiro'),
            'recharge' => [
                'contract' => $f('main', __('Contrato', 'contractlifecycle'), ['Informe o contrato', 'Contrato'], ['Item', 'ItemDropdown'], true),
                'hours'    => $f('main', __('Horas', 'contractlifecycle'), ['Quantidade de horas', 'Horas'], ['Number', 'ShortText'], true),
            ],
            'renewal' => [
                'contract' => $f('main', __('Contrato', 'contractlifecycle'), ['Informe o contrato', 'Contrato'], ['Item', 'ItemDropdown'], true),
            ],
            'termination' => [
                'option'   => $f('main', __('Opção (não renovação / cancelamento)', 'contractlifecycle'), ['Escolha uma opção', 'Opção'], ['Radio', 'Dropdown'], true),
                'contract' => $f('main', __('Contrato', 'contractlifecycle'), ['Informe o contrato', 'Contrato'], ['Item', 'ItemDropdown'], true),
                'reason'   => $f('main', __('Motivo', 'contractlifecycle'), ['Motivo'], $text),
                'date'     => $f('main', __('Data de encerramento (cancelamento)', 'contractlifecycle'), ['Data de encerramento', 'Data do cancelamento'], ['DateTime']),
            ],
            default => [],
        };
    }

    // ------------------------------------------------------------------ dados

    public static function forForm(int $forms_id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['forms_forms_id' => $forms_id]])->current();
        if ($row === null) {
            return null;
        }
        $row['mapping'] = json_decode((string) $row['mapping'], true) ?: [];
        return $row;
    }

    public static function save(int $forms_id, array $values): void
    {
        global $DB;
        if (isset($values['mapping'])) {
            $values['mapping'] = json_encode($values['mapping'], JSON_UNESCAPED_UNICODE);
        }
        $values['date_mod'] = Helpers::now();
        if ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['forms_forms_id' => $forms_id]])->count()) {
            $DB->update(self::getTable(), $values, ['forms_forms_id' => $forms_id]);
        } else {
            $DB->insert(self::getTable(), $values + ['forms_forms_id' => $forms_id]);
        }
    }

    /** Perguntas do formulário, na ordem, com a seção. */
    public static function questions(int $forms_id): array
    {
        global $DB;
        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['q.id', 'q.uuid', 'q.name', 'q.type', 'q.extra_data', 's.uuid AS section_uuid', 's.name AS section'],
            'FROM'   => 'glpi_forms_questions AS q',
            'INNER JOIN' => ['glpi_forms_sections AS s' => ['ON' => ['q' => 'forms_sections_id', 's' => 'id']]],
            'WHERE'  => ['s.forms_forms_id' => $forms_id],
            'ORDER'  => ['s.rank', 'q.vertical_rank', 'q.horizontal_rank'],
        ]);
        foreach ($iterator as $q) {
            $q['short_type'] = self::shortType((string) $q['type']);
            $q['name'] = trim((string) $q['name']);
            $q['section'] = trim((string) $q['section']);
            $out[$q['uuid']] = $q;
        }
        return $out;
    }

    public static function sections(int $forms_id): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['uuid', 'name'], 'FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => $forms_id], 'ORDER' => 'rank']) as $row) {
            $out[$row['uuid']] = trim((string) $row['name']);
        }
        return $out;
    }

    public static function shortType(string $type): string
    {
        $base = substr(strrchr('\\' . $type, '\\'), 1);
        return preg_replace('/^QuestionType/', '', $base);
    }

    public static function norm(string $text): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return trim((string) preg_replace('/[^a-z0-9\/ ]/', '', $ascii !== false ? $ascii : $text));
    }

    /**
     * Pergunta de um campo mapeado: pelo UUID; se não existir mais, pela seção + nome; por fim só pelo nome
     * (precisa ser única).
     *
     * @return array{0: ?array, 1: string} [pergunta, status]
     */
    public static function resolve(?array $map, array $questions): array
    {
        if (empty($map['uuid'])) {
            return [null, self::STATUS_UNMAPPED];
        }
        if (isset($questions[$map['uuid']])) {
            return [$questions[$map['uuid']], self::STATUS_OK];
        }
        $name = self::norm((string) ($map['name'] ?? ''));
        $section = self::norm((string) ($map['section'] ?? ''));
        foreach ([true, false] as $with_section) {
            $found = array_filter($questions, fn ($q) => self::norm($q['name']) === $name
                && (!$with_section || self::norm($q['section']) === $section));
            if (count($found) === 1) {
                return [reset($found), self::STATUS_RENAMED];
            }
        }
        return [null, self::STATUS_MISSING];
    }

    /** Sugere a pergunta de um campo pelas dicas de nome (e seção). */
    public static function guess(array $field, array $questions): ?array
    {
        foreach ($field['hints'] as $hint) {
            [$section, $name] = is_array($hint) ? $hint : [null, $hint];
            $name = self::norm($name);
            foreach ($questions as $q) {
                if (!in_array($q['short_type'], $field['types'], true)) {
                    continue;
                }
                $qname = self::norm($q['name']);
                $name_ok = $qname === $name || ($name !== '' && str_starts_with($qname, $name));
                $section_ok = $section === null || self::norm($q['section']) === self::norm($section);
                if ($name_ok && $section_ok) {
                    return $q;
                }
            }
        }
        return null;
    }

    /** Área do catálogo de uma seção de serviços, pelo prefixo do nome (ex.: "Cloud Backup" → Cloud). */
    public static function guessArea(string $section, array $areas): string
    {
        $best = '';
        foreach ($areas as $area) {
            $a = self::norm($area);
            $s = self::norm($section);
            if (($s === $a || str_starts_with($s, $a . ' ')) && strlen($a) > strlen($best)) {
                $best = $area;
            }
        }
        return $best;
    }

    public static function mapEntry(array $q): array
    {
        return ['uuid' => $q['uuid'], 'name' => $q['name'], 'section' => $q['section']];
    }

    /** Preenche o que falta (e corrige o que foi reencontrado pelo nome). Retorna quantos campos mudaram. */
    public static function autoMap(int $forms_id, string $action, array $mapping, bool $only_missing = true): array
    {
        $questions = self::questions($forms_id);
        $changed = 0;
        foreach (self::fields($action) as $key => $field) {
            [$q, $status] = self::resolve($mapping[$key] ?? null, $questions);
            if ($status === self::STATUS_OK && $only_missing) {
                continue;
            }
            if ($status === self::STATUS_RENAMED && $q) {
                $mapping[$key] = self::mapEntry($q);
                $changed++;
                continue;
            }
            $guess = self::guess($field, $questions);
            if ($guess && ($mapping[$key]['uuid'] ?? null) !== $guess['uuid']) {
                $mapping[$key] = self::mapEntry($guess);
                $changed++;
            }
        }
        if ($action === 'onboard') {
            $areas = Settings::catalogAreas();
            $current = $mapping['_sections'] ?? [];
            $sections = [];
            foreach (self::sections($forms_id) as $uuid => $name) {
                $area = $current[$uuid]['area'] ?? self::guessArea($name, $areas);
                $sections[$uuid] = ['name' => $name, 'area' => $area];
            }
            if ($sections != $current) {
                $changed++;
            }
            $mapping['_sections'] = $sections;
        }
        return [$mapping, $changed];
    }

    // ------------------------------------------------------------------ aba no formulário

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Form || $item->isNewItem() || !Session::haveRight(Settings::RIGHT, READ)) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, $item::getType(), Menu::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Form) {
            return false;
        }
        $forms_id = (int) $item->getID();
        $config = self::forForm($forms_id) ?? ['action' => '', 'is_active' => 1, 'mapping' => []];
        $questions = self::questions($forms_id);
        $action = (string) $config['action'];

        $groups = [];
        $summary = [self::STATUS_OK => 0, self::STATUS_RENAMED => 0, self::STATUS_MISSING => 0, self::STATUS_UNMAPPED => 0];
        foreach (self::fields($action) as $key => $field) {
            [$q, $status] = self::resolve($config['mapping'][$key] ?? null, $questions);
            $summary[$status]++;
            $options = ['' => '—'];
            foreach ($questions as $uuid => $question) {
                if (in_array($question['short_type'], $field['types'], true)) {
                    $options[$question['section']][$uuid] = $question['name'];
                }
            }
            $groups[$field['group']][] = [
                'key'      => $key,
                'label'    => $field['label'],
                'required' => $field['required'],
                'status'   => $status,
                'was'      => $config['mapping'][$key] ?? null,
                'types'    => implode(', ', $field['types']),
                'input'    => \Dropdown::showFromArray("mapping[{$key}]", $options, [
                    'value' => $q['uuid'] ?? '', 'display' => false, 'width' => '100%',
                ]),
            ];
        }

        $sections = [];
        if ($action === 'onboard') {
            $areas = ['' => __('— não é seção de serviços', 'contractlifecycle')] + Settings::catalogAreas();
            foreach (self::sections($forms_id) as $uuid => $name) {
                $area = $config['mapping']['_sections'][$uuid]['area'] ?? '';
                $count = count(array_filter($questions, fn ($q) => $q['section_uuid'] === $uuid && $q['short_type'] === 'Checkbox'));
                $sections[] = [
                    'uuid'  => $uuid,
                    'name'  => $name,
                    'count' => $count,
                    'input' => \Dropdown::showFromArray("sections[{$uuid}]", $areas, ['value' => $area, 'display' => false, 'width' => '100%']),
                ];
            }
        }

        echo Helpers::render('formaction.html.twig', [
            'forms_id'  => $forms_id,
            'config'    => $config,
            'actions'   => self::actions(),
            'groups'    => $groups,
            'group_labels' => self::groupLabels(),
            'sections'  => $sections,
            'summary'   => $summary,
            'runs'      => FormRunner::runsOf($forms_id),
            'can_edit'  => Session::haveRight(Settings::RIGHT, UPDATE),
            'action_url' => Helpers::url('front/formaction.form.php'),
            'ticket_url' => \Ticket::getFormURL(),
            'rand'      => mt_rand(),
        ]);
        return true;
    }

    public static function handlePost(array $post): void
    {
        $forms_id = (int) ($post['forms_id'] ?? 0);
        $form = new Form();
        if (!$form->getFromDB($forms_id)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        $action = array_key_exists($post['action_type'] ?? '', self::actions()) ? $post['action_type'] : '';
        $previous = self::forForm($forms_id);
        $questions = self::questions($forms_id);

        $mapping = [];
        if ($action !== '' && $action === ($previous['action'] ?? null)) {
            foreach (self::fields($action) as $key => $field) {
                $uuid = (string) ($post['mapping'][$key] ?? '');
                if ($uuid !== '' && isset($questions[$uuid])) {
                    $mapping[$key] = self::mapEntry($questions[$uuid]);
                }
            }
            if ($action === 'onboard') {
                $areas = Settings::catalogAreas();
                foreach (self::sections($forms_id) as $uuid => $name) {
                    $area = (string) ($post['sections'][$uuid] ?? '');
                    $mapping['_sections'][$uuid] = ['name' => $name, 'area' => isset($areas[$area]) ? $area : ''];
                }
            }
        }
        $automap = isset($post['automap']) || $action !== ($previous['action'] ?? null);
        if ($automap && $action !== '') {
            [$mapping, $changed] = self::autoMap($forms_id, $action, $mapping, !isset($post['automap']) || empty($post['remap']));
            Session::addMessageAfterRedirect(sprintf(__('Mapeamento automático: %d campo(s) ajustado(s).', 'contractlifecycle'), $changed));
        }
        self::save($forms_id, [
            'action'    => $action,
            'is_active' => empty($post['is_active']) ? 0 : 1,
            'mapping'   => $mapping,
        ]);
        Session::addMessageAfterRedirect(__('Automação do formulário salva.', 'contractlifecycle'));
    }
}
