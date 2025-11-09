<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_login();

// Optional dev diagnostics: uncomment for troubleshooting only
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

$appsPdo = get_pdo();        // APPS (punchlist) DB
$corePdo = get_pdo('core');  // CORE (users/roles/sectors/activity) DB — may be same as APPS if not split

$canManage    = can('inventory_manage');
$isRoot       = current_user_role_key() === 'root';
$userSectorId = current_user_sector_id();

$errors = [];

// --- POST actions ---
if (is_post()) {
    try {
        if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
            $errors[] = 'Invalid CSRF token.';
        } elseif (!$canManage) {
            $errors[] = 'Insufficient permissions.';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'create_item') {
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $quantity = max(0, (int)($_POST['quantity'] ?? 0));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';
                $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;

                if ($name === '') {
                    $errors[] = 'Name is required.';
                }
                if (!$isRoot && $sectorId === null) {
                    $errors[] = 'Your sector must be assigned before creating items.';
                }

                if (!$errors) {
                    $stmt = $appsPdo->prepare('
                        INSERT INTO inventory_items (sku, name, sector_id, quantity, location)
                        VALUES (:sku, :name, :sector_id, :quantity, :location)
                    ');
                    $stmt->execute([
                        ':sku'       => $sku !== '' ? $sku : null,
                        ':name'      => $name,
                        ':sector_id' => $sectorId,
                        ':quantity'  => $quantity,
                        ':location'  => $location !== '' ? $location : null,
                    ]);
                    $itemId = (int)$appsPdo->lastInsertId();

                    if ($quantity > 0) {
                        $movStmt = $appsPdo->prepare('
                            INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                            VALUES (:item_id, :direction, :amount, :reason, :user_id)
                        ');
                        $movStmt->execute([
                            ':item_id'  => $itemId,
                            ':direction'=> 'in',
                            ':amount'   => $quantity,
                            ':reason'   => 'Initial quantity',
                            ':user_id'  => current_user()['id'] ?? null,
                        ]);
                    }
                    log_event('inventory.add', 'inventory_item', $itemId, ['quantity' => $quantity, 'sector_id' => $sectorId]);
                    redirect_with_message('inventory.php', 'Item added.');
                }

            } elseif ($action === 'update_item') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $name     = trim((string)($_POST['name'] ?? ''));
                $sku      = trim((string)($_POST['sku'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $sectorInput = $_POST['sector_id'] ?? '';

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } else {
                    $sectorId = $isRoot ? (($sectorInput === '' || $sectorInput === 'null') ? null : (int)$sectorInput) : $userSectorId;
                    if (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                        $errors[] = 'Cannot edit items from other sectors.';
                    }
                    if ($name === '') {
                        $errors[] = 'Name is required.';
                    }
                    if (!$isRoot && $sectorId === null) {
                        $errors[] = 'Your sector must be assigned before editing items.';
                    }
                    if (!$errors) {
                        $updStmt = $appsPdo->prepare('
                            UPDATE inventory_items
                            SET name=:name, sku=:sku, location=:location, sector_id=:sector_id
                            WHERE id=:id
                        ');
                        $updStmt->execute([
                            ':name'      => $name,
                            ':sku'       => $sku !== '' ? $sku : null,
                            ':location'  => $location !== '' ? $location : null,
                            ':sector_id' => $sectorId,
                            ':id'        => $itemId,
                        ]);
                        redirect_with_message('inventory.php', 'Item updated.');
                    }
                }

            } elseif ($action === 'move_stock') {
                $itemId   = (int)($_POST['item_id'] ?? 0);
                $direction= $_POST['direction'] === 'out' ? 'out' : 'in';
                $amount   = max(1, (int)($_POST['amount'] ?? 0));
                $reason   = trim((string)($_POST['reason'] ?? ''));

                $itemStmt = $appsPdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
                $itemStmt->execute([$itemId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $errors[] = 'Item not found.';
                } elseif (!$isRoot && (int)$item['sector_id'] !== (int)$userSectorId) {
                    $errors[] = 'Cannot move stock for other sectors.';
                } else {
                    $delta = $direction === 'in' ? $amount : -$amount;
                    $newQuantity = (int)$item['quantity'] + $delta;
                    if ($newQuantity < 0) {
                        $errors[] = 'Not enough stock to move.';
                    } else {
                        $appsPdo->beginTransaction();
                        try {
                            $appsPdo->prepare('UPDATE inventory_items SET quantity = quantity + :delta WHERE id = :id')
                                    ->execute([':delta' => $delta, ':id' => $itemId]);

                            $appsPdo->prepare('
                                INSERT INTO inventory_movements (item_id, direction, amount, reason, user_id)
                                VALUES (:item_id, :direction, :amount, :reason, :user_id)
                            ')->execute([
                                ':item_id'  => $itemId,
                                ':direction'=> $direction,
                                ':amount'   => $amount,
                                ':reason'   => $reason !== '' ? $reason : null,
                                ':user_id'  => current_user()['id'] ?? null,
                            ]);

                            $appsPdo->commit();
                            log_event('inventory.move', 'inventory_item', $itemId, ['direction' => $direction, 'amount' => $amount]);
                            redirect_with_message('inventory.php', 'Stock updated.');
                        } catch (Throwable $e) {
                            $appsPdo->rollBack();
                            $errors[] = 'Unable to record movement.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// --- Fetch sectors (CORE) ---
$sectorOptions = [];
try {
    $sectorOptions = $corePdo->query('SELECT id, name FROM sectors ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Sectors table missing in CORE DB (or query failed).';
}

// --- Sector filter logic ---
if ($isRoot) {
    $sectorFilter = $_GET['sector'] ?? '';
} elseif ($userSectorId !== null) {
    $sectorFilter = (string)$userSectorId;
} else {
    $sectorFilter = 'null';
}

$where = [];
$params= [];
if ($sectorFilter !== '' && $sectorFilter !== 'all') {
    if ($sectorFilter === 'null') {
        $where[] = 'sector_id IS NULL';
    } else {
        $where[] = 'sector_id = :sector';
        $params[':sector'] = (int)$sectorFilter;
    }
}
if (!$isRoot && $userSectorId !== null) {
    $where[] = 'sector_id = :my_sector';
    $params[':my_sector'] = (int)$userSectorId;
}
if (!$isRoot && $userSectorId === null) {
    $where[] = 'sector_id IS NULL';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Fetch items & recent movements (APPS) ---
$items = [];
$movementsByItem = [];

try {
    $itemStmt = $appsPdo->prepare("SELECT * FROM inventory_items $whereSql ORDER BY name");
    $itemStmt->execute($params);
    $items = $itemStmt->fetchAll();

    if ($items) {
        $movementStmt = $appsPdo->prepare('SELECT * FROM inventory_movements WHERE item_id = ? ORDER BY ts DESC LIMIT 5');
        foreach ($items as $item) {
            $movementStmt->execute([$item['id']]);
            $movementsByItem[$item['id']] = $movementStmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Inventory tables missing in APPS DB (or query failed).';
}

$totalQuantity = 0;
$lowStockThreshold = 5;
$lowStockCount = 0;
$outOfStockCount = 0;
$uniqueSkus = 0;
$sectorBreakdown = [];
$skuSeen = [];

foreach ($items as $item) {
    $quantity = (int)($item['quantity'] ?? 0);
    $totalQuantity += $quantity;
    if ($quantity === 0) {
        $outOfStockCount++;
    } elseif ($quantity <= $lowStockThreshold) {
        $lowStockCount++;
    }

    $sku = trim((string)($item['sku'] ?? ''));
    if ($sku !== '' && !isset($skuSeen[$sku])) {
        $skuSeen[$sku] = true;
    }

    $sectorLabel = (string)$item['sector_id'];
    if (!isset($sectorBreakdown[$sectorLabel])) {
        $sectorBreakdown[$sectorLabel] = [
            'items' => 0,
            'quantity' => 0,
        ];
    }
    $sectorBreakdown[$sectorLabel]['items']++;
    $sectorBreakdown[$sectorLabel]['quantity'] += $quantity;
}

$uniqueSkus = count($skuSeen);

$topItems = $items;
usort($topItems, static function ($a, $b) {
    return ((int)($b['quantity'] ?? 0)) <=> ((int)($a['quantity'] ?? 0));
});
$topItems = array_slice($topItems, 0, 4);

$lowStockItems = array_values(array_filter($items, static function ($item) use ($lowStockThreshold) {
    return (int)($item['quantity'] ?? 0) <= $lowStockThreshold;
}));
usort($lowStockItems, static function ($a, $b) {
    return ((int)($a['quantity'] ?? 0)) <=> ((int)($b['quantity'] ?? 0));
});
$lowStockItems = array_slice($lowStockItems, 0, 5);

$movementPulse = ['in' => 0, 'out' => 0];
$recentMovements = [];

try {
    $pulseStmt = $appsPdo->query('SELECT direction, SUM(amount) AS total FROM inventory_movements WHERE ts >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY direction');
    foreach ($pulseStmt as $row) {
        $dir = $row['direction'] === 'out' ? 'out' : 'in';
        $movementPulse[$dir] = (int)($row['total'] ?? 0);
    }
} catch (Throwable $e) {
    // ignore
}

try {
    $recentStmt = $appsPdo->query('SELECT m.*, i.name AS item_name FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id ORDER BY m.ts DESC LIMIT 12');
    $recentMovements = $recentStmt ? $recentStmt->fetchAll() : [];
} catch (Throwable $e) {
    $recentMovements = [];
}

if ($recentMovements) {
    $userIds = [];
    foreach ($recentMovements as $movement) {
        $uid = $movement['user_id'] ?? null;
        if ($uid) {
            $userIds[(int)$uid] = true;
        }
    }
    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $userStmt = $corePdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id IN ($placeholders)");
            $userStmt->execute(array_keys($userIds));
            $names = [];
            foreach ($userStmt->fetchAll() as $row) {
                $names[(int)$row['id']] = trim((string)($row['full_name'] ?? '')) ?: 'User #' . (int)$row['id'];
            }
            foreach ($recentMovements as $idx => $movement) {
                $uid = (int)($movement['user_id'] ?? 0);
                $recentMovements[$idx]['user_name'] = $uid && isset($names[$uid]) ? $names[$uid] : 'System';
            }
        } catch (Throwable $e) {
            // ignore name lookup failures
        }
    }
}

// --- Helper to resolve sector name ---
function sector_name_by_id(array $sectors, $id): string {
    foreach ($sectors as $s) {
        if ((string)$s['id'] === (string)$id) return (string)$s['name'];
    }
    return '';
}

$sectorBreakdownDisplay = [];
foreach ($sectorBreakdown as $sectorId => $stats) {
    $label = sector_name_by_id((array)$sectorOptions, $sectorId);
    if ($label === '') {
        $label = 'Unassigned';
    }
    $sectorBreakdownDisplay[] = [
        'label'    => $label,
        'items'    => $stats['items'],
        'quantity' => $stats['quantity'],
    ];
}
usort($sectorBreakdownDisplay, static function ($a, $b) {
    return $b['quantity'] <=> $a['quantity'];
});

$title = 'Inventory';
include __DIR__ . '/includes/header.php';
?>

<section class="inventory-hero">
  <div class="inventory-hero__inner">
    <div>
      <p class="eyebrow">Quantum Inventory Deck</p>
      <h1>Inventory Command Center</h1>
      <p class="lead">Monitor velocity, surface weak spots, and orchestrate stock movements with a tactile, future-forward console.</p>
    </div>
    <div class="hero-counters">
      <div class="counter">
        <span class="counter__label">Items tracked</span>
        <span class="counter__value"><?php echo number_format(count($items)); ?></span>
      </div>
      <div class="counter">
        <span class="counter__label">Total on hand</span>
        <span class="counter__value"><?php echo number_format($totalQuantity); ?></span>
      </div>
      <div class="counter">
        <span class="counter__label">Unique SKUs</span>
        <span class="counter__value"><?php echo number_format($uniqueSkus); ?></span>
      </div>
      <div class="counter <?php echo $lowStockCount > 0 ? 'is-alert' : ''; ?>">
        <span class="counter__label">Low / Out</span>
        <span class="counter__value"><?php echo number_format($lowStockCount); ?><span class="counter__sub">/<?php echo number_format($outOfStockCount); ?></span></span>
      </div>
    </div>
  </div>
  <div class="inventory-hero__pulse">
    <article>
      <h2>30 day flow</h2>
      <dl>
        <div>
          <dt>Stock In</dt>
          <dd><?php echo number_format($movementPulse['in']); ?></dd>
        </div>
        <div>
          <dt>Stock Out</dt>
          <dd><?php echo number_format($movementPulse['out']); ?></dd>
        </div>
      </dl>
      <p class="tiny muted">Pulse aggregates the last 30 days of recorded movements.</p>
    </article>
    <article>
      <h2>Focus items</h2>
      <ul>
        <?php foreach ($lowStockItems as $focus): ?>
          <li>
            <strong><?php echo sanitize((string)$focus['name']); ?></strong>
            <span><?php echo (int)$focus['quantity']; ?> on hand</span>
          </li>
        <?php endforeach; ?>
        <?php if (!$lowStockItems): ?>
          <li class="muted">All items healthy.</li>
        <?php endif; ?>
      </ul>
    </article>
  </div>
</section>

<?php if ($errors): ?>
  <section class="card card--alert">
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  </section>
<?php endif; ?>

<section class="card inventory-console">
  <div class="inventory-console__grid">
    <form method="get" class="filters" autocomplete="off">
      <h2 class="filters__title">Sector Lens</h2>
      <label>Sector
        <select name="sector" <?php echo $isRoot ? '' : 'disabled'; ?>>
          <option value="all" <?php echo ($sectorFilter === '' || $sectorFilter === 'all') ? 'selected' : ''; ?>>All</option>
          <option value="null" <?php echo $sectorFilter === 'null' ? 'selected' : ''; ?>>Unassigned</option>
          <?php foreach ((array)$sectorOptions as $sector): ?>
            <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$sector['id'] === (string)$sectorFilter) ? 'selected' : ''; ?>>
              <?php echo sanitize((string)$sector['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="filter-actions">
        <?php if ($isRoot): ?>
          <button class="btn primary" type="submit">Apply lens</button>
          <a class="btn secondary" href="inventory.php">Reset</a>
        <?php else: ?>
          <span class="muted small">Filtering limited to your sector.</span>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($canManage): ?>
    <form method="post" class="filters filters--panel" autocomplete="off">
      <h2 class="filters__title">Create inventory node</h2>
      <label>Name
        <input type="text" name="name" required placeholder="Quantum smart bulb E27">
      </label>

      <label>SKU
        <input type="text" name="sku" placeholder="Optional SKU">
      </label>

      <label>Initial quantity
        <input type="number" name="quantity" min="0" value="0">
      </label>

      <label>Location
        <input type="text" name="location" placeholder="Aisle / Shelf">
      </label>

      <?php if ($isRoot): ?>
        <label>Sector
          <select name="sector_id">
            <option value="null">Unassigned</option>
            <?php foreach ((array)$sectorOptions as $sector): ?>
              <option value="<?php echo (int)$sector['id']; ?>"><?php echo sanitize((string)$sector['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>

      <div class="filter-actions">
        <input type="hidden" name="action" value="create_item">
        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
        <button class="btn primary" type="submit">Launch node</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</section>


<section class="inventory-layout">
  <div class="inventory-layout__main card">
    <header class="inventory-header">
      <div>
        <h2>Live inventory map</h2>
        <p class="muted">Search, filter, and orchestrate movements without leaving the grid.</p>
      </div>
      <div class="inventory-search">
        <input type="search" placeholder="Search by name, SKU, location" data-inventory-search>
      </div>
    </header>

    <div class="inventory-quick-filters" role="group" aria-label="Quick filters">
      <button type="button" class="chip-toggle is-active" data-filter="all">All</button>
      <button type="button" class="chip-toggle" data-filter="low">Low stock</button>
      <button type="button" class="chip-toggle" data-filter="out">Out of stock</button>
      <button type="button" class="chip-toggle" data-filter="healthy">Healthy</button>
    </div>

    <div class="inventory-table-wrapper">
      <table class="table table--cards compact-rows inventory-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>SKU</th>
            <th>Sector</th>
            <th>Quantity</th>
            <th>Location</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <?php
            $quantity = (int)($item['quantity'] ?? 0);
            $skuValue = trim((string)($item['sku'] ?? ''));
            $locationValue = trim((string)($item['location'] ?? ''));
            $sectorText = sector_name_by_id((array)$sectorOptions, $item['sector_id']);
            $sectorLabel = $sectorText !== '' ? $sectorText : 'Unassigned';
            $state = $quantity === 0 ? 'out' : ($quantity <= $lowStockThreshold ? 'low' : 'healthy');
          ?>
          <tr data-inventory-row data-state="<?php echo $state; ?>" data-name="<?php echo sanitize(strtolower((string)$item['name'])); ?>" data-sku="<?php echo sanitize(strtolower($skuValue)); ?>" data-location="<?php echo sanitize(strtolower($locationValue)); ?>" data-quantity="<?php echo $quantity; ?>">
            <td data-label="Name">
              <strong><?php echo sanitize((string)$item['name']); ?></strong>
              <?php if ($state !== 'healthy'): ?>
                <span class="status-pill status-pill--<?php echo $state; ?>"><?php echo $state === 'out' ? 'Out of stock' : 'Low'; ?></span>
              <?php endif; ?>
            </td>
            <td data-label="SKU">
              <?php echo $skuValue !== '' ? sanitize($skuValue) : '<em class="muted">—</em>'; ?>
            </td>
            <td data-label="Sector">
              <?php echo $sectorText !== '' ? sanitize($sectorText) : '<span class="badge">Unassigned</span>'; ?>
            </td>
            <td data-label="Quantity"><strong><?php echo $quantity; ?></strong></td>
            <td data-label="Location">
              <?php echo $locationValue !== '' ? sanitize($locationValue) : '<em class="muted">—</em>'; ?>
            </td>
            <td data-label="Actions" class="text-right">
              <details class="item-actions">
                <summary class="btn small">Manage</summary>
                <div class="item-actions__box">
                  <?php if ($canManage && ($isRoot || (int)$item['sector_id'] === (int)$userSectorId)): ?>
                    <form method="post" class="filters stack" autocomplete="off">
                      <h3>Edit basics</h3>
                      <label>Name
                        <input type="text" name="name" value="<?php echo sanitize((string)$item['name']); ?>" required>
                      </label>
                      <label>SKU
                        <input type="text" name="sku" value="<?php echo sanitize($skuValue); ?>">
                      </label>
                      <label>Location
                        <input type="text" name="location" value="<?php echo sanitize($locationValue); ?>">
                      </label>
                      <?php if ($isRoot): ?>
                        <label>Sector
                          <select name="sector_id">
                            <option value="null" <?php echo $item['sector_id'] === null ? 'selected' : ''; ?>>Unassigned</option>
                            <?php foreach ((array)$sectorOptions as $sector): ?>
                              <option value="<?php echo (int)$sector['id']; ?>" <?php echo ((string)$item['sector_id'] === (string)$sector['id']) ? 'selected' : ''; ?>>
                                <?php echo sanitize((string)$sector['name']); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                      <?php endif; ?>
                      <div class="filter-actions">
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <button class="btn small" type="submit">Save changes</button>
                      </div>
                    </form>

                    <form method="post" class="filters stack" autocomplete="off">
                      <h3>Record movement</h3>
                      <label>Direction
                        <select name="direction">
                          <option value="in">In</option>
                          <option value="out">Out</option>
                        </select>
                      </label>
                      <label>Amount
                        <input type="number" name="amount" min="1" value="1" required>
                      </label>
                      <label>Reason
                        <input type="text" name="reason" placeholder="Optional reason">
                      </label>
                      <div class="filter-actions">
                        <input type="hidden" name="action" value="move_stock">
                        <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                        <button class="btn small primary" type="submit">Record</button>
                      </div>
                    </form>
                  <?php else: ?>
                    <p class="muted small">No management rights for this item.</p>
                  <?php endif; ?>

                  <h3 class="movements-title">Recent movements</h3>
                  <ul class="movements">
                    <?php foreach ($movementsByItem[$item['id']] ?? [] as $move): ?>
                      <li>
                        <span class="chip <?php echo $move['direction'] === 'out' ? 'chip-out':'chip-in'; ?>">
                          <?php echo sanitize(strtoupper((string)$move['direction'])); ?>
                        </span>
                        <strong><?php echo (int)$move['amount']; ?></strong>
                        <span class="muted small">
                          &middot; <?php echo sanitize((string)$move['ts']); ?>
                          <?php if (!empty($move['reason'])): ?> &middot; <?php echo sanitize((string)$move['reason']); ?><?php endif; ?>
                        </span>
                      </li>
                    <?php endforeach; ?>
                    <?php if (empty($movementsByItem[$item['id']])): ?>
                      <li class="muted small">No movements yet.</li>
                    <?php endif; ?>
                  </ul>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <aside class="inventory-layout__aside">
    <section class="card mini-card">
      <h3>Top reserves</h3>
      <ul class="mini-list">
        <?php foreach ($topItems as $top): ?>
          <li>
            <strong><?php echo sanitize((string)$top['name']); ?></strong>
            <span><?php echo (int)$top['quantity']; ?> units</span>
          </li>
        <?php endforeach; ?>
        <?php if (!$topItems): ?>
          <li class="muted">No inventory yet.</li>
        <?php endif; ?>
      </ul>
    </section>

    <section class="card mini-card">
      <h3>Sector load</h3>
      <ul class="mini-list">
        <?php foreach ($sectorBreakdownDisplay as $sectorRow): ?>
          <li>
            <strong><?php echo sanitize($sectorRow['label']); ?></strong>
            <span><?php echo number_format($sectorRow['items']); ?> items · <?php echo number_format($sectorRow['quantity']); ?> qty</span>
          </li>
        <?php endforeach; ?>
        <?php if (!$sectorBreakdownDisplay): ?>
          <li class="muted">No sectors associated.</li>
        <?php endif; ?>
      </ul>
    </section>

    <section class="card mini-card">
      <h3>Movement stream</h3>
      <ul class="movement-stream">
        <?php foreach ($recentMovements as $move): ?>
          <li>
            <div class="movement-stream__icon <?php echo ($move['direction'] === 'out') ? 'is-out' : 'is-in'; ?>" aria-hidden="true"></div>
            <div>
              <strong><?php echo sanitize((string)$move['item_name']); ?></strong>
              <p class="muted small">
                <?php echo (int)$move['amount']; ?> <?php echo $move['direction'] === 'out' ? 'dispatched' : 'received'; ?>
                · <?php echo sanitize((string)($move['user_name'] ?? 'System')); ?>
                · <?php echo sanitize((string)$move['ts']); ?>
              </p>
            </div>
          </li>
        <?php endforeach; ?>
        <?php if (!$recentMovements): ?>
          <li class="muted">No movements recorded.</li>
        <?php endif; ?>
      </ul>
    </section>
  </aside>
</section>

<script>
(function(){
  const searchInput = document.querySelector('[data-inventory-search]');
  const rows = Array.from(document.querySelectorAll('[data-inventory-row]'));
  const filterButtons = document.querySelectorAll('.inventory-quick-filters [data-filter]');
  if (!rows.length) return;

  let activeFilter = 'all';

  const normalize = (value) => (value || '').toString().toLowerCase();

  const applyFilters = () => {
    const term = normalize(searchInput ? searchInput.value : '');
    rows.forEach((row) => {
      const state = row.dataset.state || 'all';
      const matchesFilter = activeFilter === 'all' ? true : state === activeFilter;
      const composite = [row.dataset.name, row.dataset.sku, row.dataset.location].join(' ');
      const matchesSearch = term === '' ? true : composite.includes(term);
      row.style.display = matchesFilter && matchesSearch ? '' : 'none';
    });
  };

  filterButtons.forEach((button) => {
    button.addEventListener('click', () => {
      filterButtons.forEach((btn) => btn.classList.remove('is-active'));
      button.classList.add('is-active');
      activeFilter = button.dataset.filter || 'all';
      applyFilters();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      window.requestAnimationFrame(applyFilters);
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
