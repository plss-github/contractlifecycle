<?php

namespace GlpiPlugin\Contractlifecycle;

use CommonDBTM;
use CommonGLPI;
use Contract;
use ITILCategory;
use Session;

/**
 * Serviços (categorias ITIL) cobertos por um contrato. O vínculo do chamado usa o ID exato da categoria.
 */
class Service extends CommonDBTM
{
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return _n('Serviço contratado', 'Serviços contratados', $nb, 'contractlifecycle');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    /** @return int[] */
    public static function categoriesOf(int $contracts_id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'itilcategories_id', 'FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $contracts_id]]) as $row) {
            $ids[] = (int) $row['itilcategories_id'];
        }
        return $ids;
    }

    /** @return int[] contratos que listam a categoria */
    public static function contractsWithCategory(int $itilcategories_id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'contracts_id', 'FROM' => self::getTable(), 'WHERE' => ['itilcategories_id' => $itilcategories_id]]) as $row) {
            $ids[] = (int) $row['contracts_id'];
        }
        return $ids;
    }

    public static function addCategory(int $contracts_id, int $itilcategories_id): bool
    {
        global $DB;
        if ($itilcategories_id <= 0) {
            return false;
        }
        $exists = $DB->request(['FROM' => self::getTable(), 'WHERE' => [
            'contracts_id' => $contracts_id, 'itilcategories_id' => $itilcategories_id,
        ]])->count();
        if ($exists) {
            return false;
        }
        return (bool) $DB->insert(self::getTable(), ['contracts_id' => $contracts_id, 'itilcategories_id' => $itilcategories_id]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Contract || $item->isNewItem() || $withtemplate || !Contract::canView()) {
            return '';
        }
        $count = $_SESSION['glpishow_count_on_tabs'] ? count(self::categoriesOf((int) $item->getID())) : 0;
        return self::createTabEntry(self::getTypeName(2), $count, $item::getType(), self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Contract) {
            self::showForContract($item);
        }
        return true;
    }

    public static function showForContract(Contract $contract): void
    {
        global $DB;
        $id = (int) $contract->getID();
        $rows = [];
        $iterator = $DB->request([
            'SELECT' => ['s.id', 'c.id AS category_id', 'c.completename', 'e.completename AS entity'],
            'FROM'   => self::getTable() . ' AS s',
            'LEFT JOIN' => [
                ITILCategory::getTable() . ' AS c' => ['ON' => ['s' => 'itilcategories_id', 'c' => 'id']],
                \Entity::getTable() . ' AS e'       => ['ON' => ['c' => 'entities_id', 'e' => 'id']],
            ],
            'WHERE'  => ['s.contracts_id' => $id],
            'ORDER'  => 'c.completename',
        ]);
        foreach ($iterator as $row) {
            $rows[] = [
                'id'       => (int) $row['id'],
                'name'     => $row['completename'] ?? sprintf(__('Categoria #%d (removida)', 'contractlifecycle'), $row['category_id']),
                'url'      => ITILCategory::getFormURLWithID((int) $row['category_id']),
                'entity'   => $row['entity'] ?? '',
            ];
        }

        $settings = Settings::forContract($id);
        echo Helpers::render('services.html.twig', [
            'rows'     => $rows,
            'roots'    => array_filter(array_map('trim', explode("\n", (string) $settings['eligible_roots']))),
            'can_edit' => $contract->canUpdateItem(),
            'action'   => Helpers::url('front/service.form.php'),
            'contracts_id' => $id,
            'add_input' => \Dropdown::show(ITILCategory::class, [
                'name'        => 'itilcategories_id[]', // select múltiplo: o Dropdown não acrescenta []
                'multiple'    => true,
                'values'      => [],
                'entity'      => (int) $contract->fields['entities_id'],
                'entity_sons' => (bool) $contract->fields['is_recursive'],
                'display'     => false,
                'width'       => '100%',
            ]),
            'rand' => mt_rand(),
        ]);
    }

    public static function handlePost(array $post): void
    {
        $contract = new Contract();
        if (!$contract->getFromDB((int) ($post['contracts_id'] ?? 0)) || !$contract->canUpdateItem()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $id = (int) $contract->getID();
        global $DB;

        if (isset($post['add'])) {
            // O select múltiplo do GLPI envia um itilcategories_id="" oculto (para postar "nada selecionado"):
            // sem o filtro ele vira a categoria 0, e os "filhos" da 0 são o catálogo inteiro.
            $ids = array_values(array_filter(
                array_map('intval', (array) ($post['itilcategories_id'] ?? [])),
                static fn (int $cat) => $cat > 0
            ));
            if ($ids === []) {
                Session::addMessageAfterRedirect(__('Selecione ao menos uma categoria.', 'contractlifecycle'), false, WARNING);
                return;
            }
            if (!empty($post['with_children'])) {
                foreach ($ids as $cat) {
                    $ids = array_merge($ids, array_map('intval', array_keys(getSonsOf(ITILCategory::getTable(), $cat))));
                }
            }
            $added = 0;
            foreach (array_unique($ids) as $cat) {
                $added += self::addCategory($id, $cat) ? 1 : 0;
            }
            Session::addMessageAfterRedirect(sprintf(_n('%d serviço adicionado.', '%d serviços adicionados.', $added, 'contractlifecycle'), $added));
        } elseif (isset($post['remove'])) {
            $ids = array_map('intval', array_keys((array) ($post['item'] ?? [])));
            if ($ids !== []) {
                $DB->delete(self::getTable(), ['id' => $ids, 'contracts_id' => $id]);
            }
            Session::addMessageAfterRedirect(sprintf(_n('%d serviço removido.', '%d serviços removidos.', count($ids), 'contractlifecycle'), count($ids)));
        }
    }

    public static function categoryPurged(ITILCategory $category): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['itilcategories_id' => (int) $category->getID()]);
    }
}
