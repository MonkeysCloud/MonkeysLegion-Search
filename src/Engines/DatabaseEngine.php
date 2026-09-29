<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Engines;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\{Facet, IndexConfig, SearchHit, SearchQuery, SearchResult, Suggestion};
use PDO;
use PDOException;

/**
 * MonKeysLegion Framework — Search Package
 *
 * Database engine — uses SQL LIKE queries for full-text search.
 *
 * No external service required. Each index is stored as a table
 * with a JSON `data` column. Perfect for small datasets or as
 * a fallback when no search engine is available.
 *
 * Schema (per index):
 *   CREATE TABLE search_{index_name} (
 *     id   VARCHAR(255) PRIMARY KEY,
 *     data JSON NOT NULL,
 *     FULLTEXT(data)  -- MySQL only
 *   );
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
final class DatabaseEngine implements SearchEngineInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = 'search_',
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);
        $table = $this->tableName($query->indexName);

        // Build SQL with LIKE matching
        $sql = "SELECT id, data FROM {$table} WHERE 1=1";
        $params = [];

        // Term search
        if ($query->term !== '') {
            $sql .= " AND (CAST(data AS CHAR) LIKE :term)";
            $params['term'] = '%' . $query->term . '%';
        }

        // Filters
        foreach ($query->filters as $i => $filter) {
            $field = $filter['field'];
            $operator = $filter['operator'];
            $value = $filter['value'];
            $paramName = "filter_{$i}";

            $sqlOperator = match ($operator) {
                '='     => '=',
                '!=', '<>' => '!=',
                '>'     => '>',
                '<'     => '<',
                '>='    => '>=',
                '<='    => '<=',
                'like'  => 'LIKE',
                default => '=',
            };

            if ($operator === 'in' && is_array($value)) {
                $placeholders = implode(',', array_map(fn($k) => ":filter_{$i}_{$k}", array_keys($value)));
                $sql .= " AND JSON_EXTRACT(data, :field_{$i}) IN ({$placeholders})";
                $params["field_{$i}"] = '$.' . $field;
                foreach ($value as $k => $v) {
                    $params["filter_{$i}_{$k}"] = $v;
                }
            } else {
                $sql .= " AND JSON_EXTRACT(data, :field_{$i}) {$sqlOperator} :{$paramName}";
                $params["field_{$i}"] = '$.' . $field;
                $params[$paramName] = $value;
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) FROM ({$sql}) AS sub";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Sort
        if (!empty($query->sorts)) {
            $orderParts = [];
            foreach ($query->sorts as $i => $sort) {
                $field = $sort['field'];
                $direction = $sort['direction']->value;
                $orderParts[] = "JSON_EXTRACT(data, '$.{$field}') " . strtoupper($direction);
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        // Pagination
        $sql .= ' LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit', $query->perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $query->offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hits = [];
        foreach ($rows as $row) {
            $document = json_decode($row['data'], true) ?: [];
            $hits[] = new SearchHit(
                id: (string) $row['id'],
                score: 1.0,
                document: $document,
            );
        }

        // Facets
        $facets = [];
        foreach ($query->facets as $field) {
            $facetSql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$field}')) as val, COUNT(*) as cnt FROM {$table} WHERE 1=1";
            // Re-apply term filter for facet counts
            if ($query->term !== '') {
                $facetSql .= " AND (CAST(data AS CHAR) LIKE :term)";
            }
            $facetSql .= " GROUP BY val";
            $facetStmt = $this->pdo->prepare($facetSql);
            if ($query->term !== '') {
                $facetStmt->execute(['term' => '%' . $query->term . '%']);
            } else {
                $facetStmt->execute();
            }
            $values = [];
            foreach ($facetStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['val'] !== null) {
                    $values[(string) $row['val']] = (int) $row['cnt'];
                }
            }
            $facets[] = new Facet($field, $values);
        }

        $took = (microtime(true) - $start) * 1000;

        return new SearchResult(
            hits: $hits,
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: $took,
            meta: ['engine' => 'database'],
        );
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $table = $this->tableName($indexName);
        $sql = "INSERT INTO {$table} (id, data) VALUES (:id, :data)
                ON DUPLICATE KEY UPDATE data = VALUES(data)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'   => $id,
            'data' => json_encode($document, JSON_THROW_ON_ERROR),
        ]);
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $count = 0;
        foreach ($documents as $document) {
            $id = (string) ($document['id'] ?? uniqid());
            $this->index($indexName, $id, $document);
            $count++;
        }
        return $count;
    }

    public function delete(string $indexName, string $id): void
    {
        $table = $this->tableName($indexName);
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        if (empty($ids)) return 0;
        $table = $this->tableName($indexName);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    public function createIndex(IndexConfig $config): void
    {
        $table = $this->tableName($config->name);
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id   VARCHAR(255) PRIMARY KEY,
            data JSON NOT NULL
        )";
        $this->pdo->exec($sql);
    }

    public function deleteIndex(string $indexName): void
    {
        $table = $this->tableName($indexName);
        $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    public function indexExists(string $indexName): bool
    {
        $table = $this->tableName($indexName);
        try {
            $result = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return $result !== false;
        } catch (PDOException) {
            return false;
        }
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        // Database engine doesn't support settings — no-op
    }

    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function info(): array
    {
        return [
            'engine' => 'database',
            'driver' => $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        ];
    }

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        // Database engine doesn't support raw DSL queries
        return new SearchResult(
            hits: [],
            total: 0,
            page: 1,
            perPage: 20,
            meta: ['engine' => 'database', 'note' => 'raw queries not supported'],
        );
    }

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $table = $this->tableName($indexName);
        $sql = "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')) as suggestion
                FROM {$table}
                WHERE JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')) LIKE :prefix
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':prefix', $prefix . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $suggestions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['suggestion']) {
                $suggestions[] = new Suggestion(text: (string) $row['suggestion']);
            }
        }
        return $suggestions;
    }

    private function tableName(string $indexName): string
    {
        return $this->tablePrefix . preg_replace('/[^a-zA-Z0-9_]/', '_', $indexName);
    }
}
