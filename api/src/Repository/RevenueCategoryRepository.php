<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro revenue_categories — kategorie tržeb vydaných faktur.
 *
 * Symetrie k {@see ExpenseCategoryRepository}, ale BEZ fixed_or_var (fixní/variabilní
 * je nákladový koncept). Per tenant (supplier_id). UNIQUE (supplier_id, code).
 */
final class RevenueCategoryRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeArchived = false): array
    {
        $sql = 'SELECT id, code, label, display_order, archived, created_at,
                       (SELECT COUNT(*) FROM invoices WHERE revenue_category_id = revenue_categories.id) AS invoices_count
                  FROM revenue_categories
                 WHERE supplier_id = ?';
        if (!$includeArchived) $sql .= ' AND archived = 0';
        $sql .= ' ORDER BY display_order ASC, label ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, display_order, archived, created_at
               FROM revenue_categories WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @param array{code:string, label:string, display_order?:int} $data
     */
    public function create(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, display_order)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['label'],
            (int) ($data['display_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE revenue_categories
                SET code = ?, label = ?, display_order = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['label'],
            (int) ($data['display_order'] ?? 0),
            !empty($data['archived']) ? 1 : 0,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard delete — pokud žádná faktura nepoužívá. Jinak soft (archived=1).
     */
    public function delete(int $id, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE revenue_category_id = ?');
        $stmt->execute([$id]);
        $total = (int) $stmt->fetchColumn();

        if ($total === 0) {
            $del = $pdo->prepare('DELETE FROM revenue_categories WHERE id = ? AND supplier_id = ?');
            $del->execute([$id, $supplierId]);
            return ['deleted' => true, 'archived' => false];
        }
        // Soft delete — zachová historii odkazů
        $arch = $pdo->prepare('UPDATE revenue_categories SET archived = 1 WHERE id = ? AND supplier_id = ?');
        $arch->execute([$id, $supplierId]);
        return ['deleted' => false, 'archived' => true, 'usage_count' => $total];
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['display_order'] = (int) $r['display_order'];
        $r['archived'] = (bool) $r['archived'];
        if (isset($r['invoices_count'])) $r['invoices_count'] = (int) $r['invoices_count'];
        return $r;
    }
}
