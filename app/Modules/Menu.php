<?php

namespace App\Modules;

use App\Core\Api;
use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpError;
use App\Core\Req;
use App\Shared\Media;
use App\Shared\Units;

/** Menu (M1.3, M2.2.1): categories, items, variants, add-ons, time slots, daily availability, import, recipes. */
class Menu
{
    /** Cost of one portion from its recipe, in paise. Reused by billing (stock deduction) and reports. */
    public static function recipeCost(string $itemId, ?string $variantId = null): float
    {
        static $cache = [];
        $key = spl_object_id(ctx());
        $rec = Db::first('recipes', fn ($q) => $q->where('item_id', $itemId)->where('variant_id', $variantId))
            ?? Db::first('recipes', fn ($q) => $q->where('item_id', $itemId)->whereNull('variant_id'));
        if (!$rec) return 0;
        $mats = $cache[$key] ??= array_column(Db::rows('raw_materials'), null, 'id');
        $cost = 0;
        foreach (Db::rows('recipe_lines', fn ($q) => $q->where('recipe_id', $rec['id'])) as $l) {
            $m = $mats[$l['raw_material_id']] ?? null;
            if ($m) $cost += Units::convert($l['qty'], $l['unit'], $m['unit']) * $m['avg_cost'];
        }
        return $cost;
    }

    public static function payload(bool $staff): array
    {
        $now = ctx()->now()->setTimezone(new \DateTimeZone(config('cafechalao.timezone')))->format('H:i');
        $slots = Db::rows('availability_slots');
        $groups = Db::rows('add_on_groups');
        $addOns = Db::rows('add_ons');
        $variants = Db::rows('variants');
        $defaultRate = Db::settings('tax_settings')['default_gst_rate'];
        $cats = Db::rows('categories', fn ($q) => $staff ? $q : $q->where('active', 1), 'sort');
        $catIds = array_column($cats, 'id');
        $items = array_values(array_filter(Db::rows('items', null, 'sort'), fn ($i) => in_array($i['category_id'], $catIds, true)));
        $items = array_map(function ($i) use ($slots, $groups, $addOns, $variants, $now, $staff, $defaultRate) {
            $slot = first_where($slots, fn ($s) => $s['id'] === $i['availability_slot_id']);
            $inSlot = !$slot || ($now >= $slot['start_time'] && $now <= $slot['end_time']);
            return $i + [
                'variants' => where_eq($variants, 'item_id', $i['id']),
                'add_on_groups' => array_values(array_map(fn ($g) => $g + ['add_ons' => where_eq($addOns, 'group_id', $g['id'])], array_filter($groups, fn ($g) => in_array($g['id'], $i['add_on_group_ids'] ?? [], true)))),
                'slot' => $slot,
                'available_now' => $i['in_stock'] && $i['available_today'] && $inSlot,
            ] + ($staff ? ['recipe_cost' => (int) round(self::recipeCost($i['id']))] : []);
        }, $items);
        foreach ($items as &$i) $i['gst_rate'] ??= $defaultRate;
        return ['categories' => $cats, 'items' => $items, 'slots' => $slots];
    }

    private static function validate(array $b): void
    {
        $e = [];
        if (!trim($b['name'] ?? '')) $e['name'] = 'Name is required';
        if (empty($b['category_id'])) $e['category_id'] = 'Pick a category';
        if (!isset($b['price']) || $b['price'] < 0) $e['price'] = 'Price must be 0 or more';
        if ($e) throw HttpError::invalid($e);
    }

    private static function saveVariants(string $itemId, ?array $list): void
    {
        if ($list === null) return;
        $existing = Db::rows('variants', fn ($q) => $q->where('item_id', $itemId));
        $keep = array_filter(array_column($list, 'id'));
        foreach ($existing as $v) if (!in_array($v['id'], $keep, true)) Db::remove('variants', $v['id']);
        foreach (array_values($list) as $i => $v) {
            $data = ['name' => $v['name'], 'price' => $v['price'], 'is_default' => $v['is_default'] ?? $i === 0];
            if (!empty($v['id']) && first_where($existing, fn ($x) => $x['id'] === $v['id'])) Db::update('variants', $v['id'], $data);
            else Db::create('variants', $data + ['item_id' => $itemId], 'va');
        }
    }

    public static function routes(): void
    {
        Api::get('/menu', fn () => self::payload(false), 'public');
        Api::get('/menu/manage', fn () => self::payload(true), 'staff', 'menu.edit');

        Api::crud('/categories', 'categories', 'ct', ['perm' => 'menu.edit', 'required' => ['name'], 'defaults' => ['active' => true, 'sort' => 99, 'station' => 'kitchen'], 'audit' => 'category', 'sort' => 'sort']);
        Api::crud('/availability-slots', 'availability_slots', 'as', ['perm' => 'menu.edit', 'required' => ['name', 'start_time', 'end_time']]);
        Api::crud('/add-on-groups', 'add_on_groups', 'ag', ['perm' => 'menu.edit', 'required' => ['name'], 'defaults' => ['min_select' => 0, 'max_select' => 1]]);
        Api::crud('/add-ons', 'add_ons', 'ao', ['perm' => 'menu.edit', 'required' => ['group_id', 'name'], 'defaults' => ['price' => 0, 'food_type' => 'veg']]);

        Api::post('/items', function (Req $r) {
            self::validate($r->body);
            $data = Media::storeFields($r->body, ['photo_url'], 'menu');
            $code = $data['code'] ?? '' ?: (string) (Db::q('items')->count() + 100);
            if (Db::q('items')->where('code', $code)->exists()) throw HttpError::invalid(['code' => 'This item code is already used']);
            $item = Db::create('items', array_merge(['description' => '', 'food_type' => 'veg', 'bestseller' => false, 'in_stock' => true, 'available_today' => true, 'prep_minutes' => 8, 'add_on_group_ids' => [], 'sort' => 99], $data, ['code' => $code]), 'it');
            self::saveVariants($item['id'], $r->b('variants'));
            Audit::log('create', 'item', $item['id'], null, ['name' => $item['name'], 'price' => $item['price']]);
            return $item;
        }, 'staff', 'menu.edit');

        Api::put('/items/:id', function (Req $r) {
            $old = Db::find('items', $r->p('id'), 'Item');
            $patch = Media::storeFields($r->body, ['photo_url'], 'menu');
            self::validate(array_merge($old, $patch));
            if (isset($patch['price']) && (int) $patch['price'] !== $old['price']) {
                if (!ctx()->can('menu.price')) throw HttpError::forbidden('Only the owner can change prices');
                // Old bills keep their old price: order items store a snapshot.
                Audit::log('price_change', 'item', $old['id'], ['price' => $old['price']], ['price' => (int) $patch['price']]);
            }
            $item = Db::update('items', $old['id'], $patch);
            self::saveVariants($old['id'], $r->b('variants'));
            return $item;
        }, 'staff', 'menu.edit');

        Api::delete('/items/:id', function (Req $r) {
            $old = Db::find('items', $r->p('id'), 'Item');
            Db::remove('items', $old['id']);
            Audit::log('delete', 'item', $old['id'], ['name' => $old['name']], null);
            return ['deleted' => true];
        }, 'staff', 'menu.edit');

        Api::patch('/items/:id/availability', function (Req $r) {
            if (!ctx()->can('menu.edit') && !ctx()->can('kds.use')) throw HttpError::forbidden();
            $old = Db::find('items', $r->p('id'), 'Item');
            return Db::update('items', $old['id'], array_intersect_key($r->body, array_flip(['in_stock', 'available_today'])));
        });

        Api::post('/items/availability/reset', function () {
            Db::q('items')->where('available_today', 0)->update(['available_today' => 1, 'updated_at' => ctx()->iso()]);
            return ['ok' => true];
        }, 'staff', 'menu.edit');

        /** Import: every row is checked; nothing is saved unless all pass. */
        Api::post('/menu/import', function (Req $r) {
            need($r->body, ['rows']);
            $rows = $r->b('rows');
            $errors = [];
            foreach ($rows as $i => $row) {
                $line = $i + 2;
                if (!trim($row['name'] ?? '')) $errors["row_$line"] = "Row $line: name is empty";
                elseif (!trim($row['category'] ?? '')) $errors["row_$line"] = "Row $line: category is empty";
                elseif (!is_numeric($row['price'] ?? null) || $row['price'] < 0) $errors["row_$line"] = "Row $line: price \"" . ($row['price'] ?? '') . '" is not a number';
                elseif (!empty($row['type']) && !in_array(strtolower($row['type']), ['veg', 'non_veg', 'non-veg', 'egg'], true)) $errors["row_$line"] = "Row $line: type must be veg, non-veg or egg";
            }
            if ($errors) throw new HttpError(422, count($errors) . ' row(s) have problems. Nothing was imported.', 'validation', $errors);
            $cats = Db::rows('categories');
            foreach ($rows as $row) {
                $cat = first_where($cats, fn ($c) => strtolower($c['name']) === strtolower(trim($row['category'])));
                if (!$cat) $cats[] = $cat = Db::create('categories', ['name' => trim($row['category']), 'sort' => 99, 'station' => 'kitchen', 'active' => true], 'ct');
                Db::create('items', ['category_id' => $cat['id'], 'code' => $row['code'] ?? '' ?: (string) (Db::q('items')->count() + 100), 'name' => trim($row['name']), 'description' => $row['description'] ?? '',
                    'food_type' => str_replace('-', '_', strtolower($row['type'] ?? 'veg')), 'price' => (int) round($row['price'] * 100), 'bestseller' => false, 'in_stock' => true, 'available_today' => true, 'prep_minutes' => 8, 'add_on_group_ids' => [], 'sort' => 99], 'it');
            }
            return ['created' => count($rows)];
        }, 'staff', 'menu.edit');

        /* Recipes (M5.2) */
        Api::get('/items/:id/recipe', function (Req $r) {
            $item = Db::find('items', $r->p('id'), 'Item');
            $rec = Db::first('recipes', fn ($q) => $q->where('item_id', $item['id'])->whereNull('variant_id'));
            return ['item_id' => $item['id'], 'lines' => $rec ? Db::rows('recipe_lines', fn ($q) => $q->where('recipe_id', $rec['id'])) : [], 'cost' => (int) round(self::recipeCost($item['id']))];
        }, 'staff', 'menu.edit');

        Api::put('/items/:id/recipe', function (Req $r) {
            $item = Db::find('items', $r->p('id'), 'Item');
            $rec = Db::first('recipes', fn ($q) => $q->where('item_id', $item['id'])->whereNull('variant_id')) ?? Db::create('recipes', ['item_id' => $item['id'], 'variant_id' => null], 'rc');
            Db::q('recipe_lines')->where('recipe_id', $rec['id'])->update(['deleted_at' => ctx()->iso()]);
            foreach ((array) $r->b('lines', []) as $l) {
                $m = Db::find('raw_materials', $l['raw_material_id'], 'Ingredient');
                Units::convert(1, $l['unit'], $m['unit']);
                Db::create('recipe_lines', ['recipe_id' => $rec['id'], 'raw_material_id' => $m['id'], 'qty' => (float) $l['qty'], 'unit' => $l['unit']], 'rcl');
            }
            return ['ok' => true, 'cost' => (int) round(self::recipeCost($item['id']))];
        }, 'staff', 'menu.edit');
    }
}
