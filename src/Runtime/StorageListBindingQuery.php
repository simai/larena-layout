<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use JsonException;
use Larena\Layout\Exceptions\LayoutRejected;

/**
 * The query of a `storage_list` binding: which published records of a storage a page
 * block lists.
 *
 * Layout checks the shape only — field names, operators, a sort of at most three keys,
 * a limit of at most 100. Whether the fields exist and are public is the storage's
 * answer, asked by the host when the page is saved (PageBindingValidator), because
 * Layout does not know any storage's fields.
 *
 * A list is read when the page is shown, not when it is published: its records change
 * without the page changing, so a `storage_list` binding carries no expected revision
 * and its rows never enter the page projection.
 */
final class StorageListBindingQuery
{
    public const KIND = 'storage_list';

    public const MAX_LIMIT = 100;

    public const DEFAULT_LIMIT = 20;

    public const MAX_SORTS = 3;

    public const MAX_FILTERS = 20;

    public const MAX_FILTERS_JSON_BYTES = 8192;

    public const MAX_SEARCH_LENGTH = 200;

    /** A storage role reference (`role@vN`) or a storage schema id. */
    public const TARGET_PATTERN = '/^[a-z][a-z0-9_.:-]{1,119}(?:@v[1-9][0-9]{0,3})?$/D';

    private const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    private const SORT_FIELD_PATTERN = '/^_?[a-z][a-z0-9_]{0,63}$/D';

    private const OPERATOR_PATTERN = '/^[a-z][a-z_]{0,31}$/D';

    /**
     * @param mixed $query the binding's `query`
     * @return array{filters: array<string, array<string, mixed>>, limit: int, search: ?string, sort: list<array{direction: string, field: string}>}
     */
    public static function normalize(mixed $query): array
    {
        if (!is_array($query) || ($query !== [] && array_is_list($query))) {
            throw self::reject();
        }
        if (array_diff(array_keys($query), ['filters', 'limit', 'search', 'sort']) !== []) {
            throw self::reject();
        }

        return [
            'filters' => self::filters($query['filters'] ?? []),
            'limit' => self::limit($query['limit'] ?? self::DEFAULT_LIMIT),
            'search' => self::search($query['search'] ?? null),
            'sort' => self::sort($query['sort'] ?? []),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function filters(mixed $filters): array
    {
        if ($filters === []) {
            return [];
        }
        if (!is_array($filters) || array_is_list($filters) || count($filters) > self::MAX_FILTERS) {
            throw self::reject();
        }
        try {
            if (strlen(json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > self::MAX_FILTERS_JSON_BYTES) {
                throw self::reject();
            }
        } catch (JsonException) {
            throw self::reject();
        }
        $normalized = [];
        foreach ($filters as $field => $condition) {
            if (!is_string($field) || preg_match(self::FIELD_PATTERN, $field) !== 1 || !is_array($condition)) {
                throw self::reject();
            }
            $keys = array_keys($condition);
            sort($keys, SORT_STRING);
            $operator = $condition['operator'] ?? null;
            if (!is_string($operator) || preg_match(self::OPERATOR_PATTERN, $operator) !== 1) {
                throw self::reject();
            }
            if ($keys === ['operator', 'value']) {
                $normalized[$field] = ['operator' => $operator, 'value' => self::scalar($condition['value'])];
            } elseif ($keys === ['operator', 'values']) {
                $values = $condition['values'];
                if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 100) {
                    throw self::reject();
                }
                $normalized[$field] = ['operator' => $operator, 'values' => array_map(self::scalar(...), $values)];
            } elseif ($keys === ['operator']) {
                $normalized[$field] = ['operator' => $operator];
            } else {
                throw self::reject();
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_float($value) && !is_finite($value)) {
            throw self::reject();
        }
        if (is_string($value) && (strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1)) {
            throw self::reject();
        }
        if (!is_scalar($value) && $value !== null) {
            throw self::reject();
        }

        return $value;
    }

    private static function limit(mixed $limit): int
    {
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::reject();
        }

        return $limit;
    }

    private static function search(mixed $search): ?string
    {
        if ($search === null || $search === '') {
            return null;
        }
        if (!is_string($search) || mb_strlen($search) > self::MAX_SEARCH_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $search) === 1 || trim($search) === '') {
            throw self::reject();
        }

        return $search;
    }

    /** @return list<array{direction: string, field: string}> */
    private static function sort(mixed $sort): array
    {
        if (!is_array($sort) || !array_is_list($sort) || count($sort) > self::MAX_SORTS) {
            throw self::reject();
        }
        $normalized = [];
        $fields = [];
        foreach ($sort as $item) {
            if (!is_array($item)) {
                throw self::reject();
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            $field = $item['field'] ?? null;
            $direction = $item['direction'] ?? 'asc';
            if (($keys !== ['direction', 'field'] && $keys !== ['field'])
                || !is_string($field) || preg_match(self::SORT_FIELD_PATTERN, $field) !== 1
                || !in_array($direction, ['asc', 'desc'], true) || isset($fields[$field])) {
                throw self::reject();
            }
            $fields[$field] = true;
            $normalized[] = ['direction' => $direction, 'field' => $field];
        }

        return $normalized;
    }

    private static function reject(): LayoutRejected
    {
        return new LayoutRejected('layout_storage_list_query_invalid');
    }
}
