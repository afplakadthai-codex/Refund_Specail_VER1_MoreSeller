<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$guardCandidates = [
    __DIR__ . '/_guard.php',
    dirname(__DIR__) . '/seller/_guard.php',
    dirname(__DIR__, 2) . '/seller/_guard.php',
    dirname(__DIR__) . '/include/auth.php',
    dirname(__DIR__) . '/includes/auth.php',
    dirname(__DIR__, 2) . '/_guard.php',
];
foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$refundHelperCandidates = [
    dirname(__DIR__) . '/includes/order_refund.php',
    dirname(__DIR__, 2) . '/includes/order_refund.php',
    dirname(__DIR__, 2) . '/order_refund.php',
    __DIR__ . '/../includes/order_refund.php',
];
$refundHelperLoaded = false;
foreach ($refundHelperCandidates as $refundHelper) {
    if (is_file($refundHelper)) {
        require_once $refundHelper;
        $refundHelperLoaded = true;
        break;
    }
}
if (!$refundHelperLoaded) {
    http_response_code(500);
    exit('Refund helper not found.');
}

if (!function_exists('bvsr_h')) {
    function bvsr_h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bvsr_db')) {
    function bvsr_db()
    {
        if (function_exists('bv_order_refund_db')) {
            return bv_order_refund_db();
        }

        foreach (['pdo', 'PDO', 'db', 'conn', 'mysqli'] as $key) {
            if (isset($GLOBALS[$key]) && ($GLOBALS[$key] instanceof PDO || $GLOBALS[$key] instanceof mysqli)) {
                return $GLOBALS[$key];
            }
        }

        if (function_exists('db')) {
            $conn = db();
            if ($conn instanceof PDO || $conn instanceof mysqli) {
                return $conn;
            }
        }

        throw new RuntimeException('Database connection unavailable.');
    }
}

if (!function_exists('bvsr_current_user_id')) {
    function bvsr_current_user_id(): int
    {
        if (function_exists('seller_current_user_id')) {
            $id = (int)seller_current_user_id();
            if ($id > 0) {
                return $id;
            }
        }

        if (function_exists('bv_order_refund_current_user_id')) {
            $id = (int)bv_order_refund_current_user_id();
            if ($id > 0) {
                return $id;
            }
        }

        $candidates = [
            $_SESSION['seller_id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['member_id'] ?? null,
            $_SESSION['auth_user_id'] ?? null,
            $_SESSION['id'] ?? null,
            $_SESSION['user']['id'] ?? null,
            $_SESSION['member']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int)$candidate > 0) {
                return (int)$candidate;
            }
        }

        return 0;
    }
}

if (!function_exists('bvsr_current_user_role')) {
    function bvsr_current_user_role(): string
    {
        if (function_exists('bv_order_refund_current_user_role')) {
            $role = strtolower(trim((string)bv_order_refund_current_user_role()));
            if ($role !== '') {
                return $role;
            }
        }

        $candidates = [
            $_SESSION['seller_role'] ?? null,
            $_SESSION['user_role'] ?? null,
            $_SESSION['role'] ?? null,
            $_SESSION['account_role'] ?? null,
            $_SESSION['user']['role'] ?? null,
            $_SESSION['member']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        return 'guest';
    }
}

if (!function_exists('bvsr_is_seller')) {
    function bvsr_is_seller(): bool
    {
        $userId = bvsr_current_user_id();
        if ($userId <= 0) {
            return false;
        }

        if (function_exists('seller_is_approved') && seller_is_approved($userId)) {
            return true;
        }

        $role = bvsr_current_user_role();
        return in_array($role, ['seller', 'vendor', 'merchant', 'shop_owner'], true);
    }
}

if (!function_exists('bvsr_build_url')) {
    function bvsr_build_url(string $path, array $params = []): string
    {
        if ($path === '') {
            $path = '/seller/refunds.php';
        }

        if ($params === []) {
            return $path;
        }

        $query = http_build_query($params);
        if ($query === '') {
            return $path;
        }

        return $path . (strpos($path, '?') === false ? '?' : '&') . $query;
    }
}

if (!function_exists('bvsr_is_safe_return_url')) {
    function bvsr_is_safe_return_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) {
            return false;
        }
        if (strpos($url, '//') === 0) {
            return false;
        }
        if (stripos($url, 'javascript:') === 0 || stripos($url, 'data:') === 0) {
            return false;
        }
        if (strpos($url, "\r") !== false || strpos($url, "\n") !== false) {
            return false;
        }

        return true;
    }
}

if (!function_exists('bvsr_refund_status_badge')) {
    function bvsr_refund_status_badge(string $status): array
    {
        $status = strtolower(trim($status));
        $map = [
            'pending_approval' => ['label' => 'Pending Approval', 'class' => 'warning'],
            'approved' => ['label' => 'Approved', 'class' => 'success'],
            'processing' => ['label' => 'Processing', 'class' => 'info'],
            'partially_refunded' => ['label' => 'Partially Refunded', 'class' => 'info'],
            'refunded' => ['label' => 'Refunded', 'class' => 'success'],
            'rejected' => ['label' => 'Rejected', 'class' => 'danger'],
            'failed' => ['label' => 'Failed', 'class' => 'danger'],
            'cancelled' => ['label' => 'Cancelled', 'class' => 'muted'],
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return [
            'label' => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Unknown',
            'class' => 'muted',
        ];
    }
}

if (!function_exists('bvsr_money')) {
    function bvsr_money($amount, ?string $currency = null): string
    {
        $value = is_numeric($amount) ? (float)$amount : 0.0;
        $code = strtoupper(trim((string)$currency));

        return $code !== ''
            ? $code . ' ' . number_format($value, 2)
            : number_format($value, 2);
    }
}

if (!function_exists('bvsr_csrf_token')) {
    function bvsr_csrf_token(): string
    {
        $paths = [
            ['_csrf_seller_refunds', 'refund_actions'],
            ['_csrf_seller_refunds', 'seller_refund_actions'],
            ['_csrf', 'refund_action'],
            ['csrf', 'token'],
        ];

        foreach ($paths as $path) {
            [$root, $leaf] = $path;
            if (isset($_SESSION[$root]) && is_array($_SESSION[$root]) && isset($_SESSION[$root][$leaf]) && is_string($_SESSION[$root][$leaf])) {
                $token = trim($_SESSION[$root][$leaf]);
                if ($token !== '') {
                    return $token;
                }
            }
        }

        foreach (['csrf_token', '_csrf_token', 'seller_csrf_token', 'refund_csrf_token'] as $k) {
            if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && trim($_SESSION[$k]) !== '') {
                return trim($_SESSION[$k]);
            }
        }

        return '';
    }
}

if (!function_exists('bvsr_consume_flash')) {
    function bvsr_consume_flash(): array
    {
        $messages = [];

        if (isset($_SESSION['seller_refund_flash']) && is_array($_SESSION['seller_refund_flash'])) {
            $type = strtolower(trim((string)($_SESSION['seller_refund_flash']['type'] ?? 'info')));
            $message = trim((string)($_SESSION['seller_refund_flash']['message'] ?? ''));
            if ($message !== '') {
                $messages[] = ['type' => $type, 'message' => $message];
            }
            unset($_SESSION['seller_refund_flash']);
        }

        if (isset($_SESSION['flash_success']) && is_string($_SESSION['flash_success']) && trim($_SESSION['flash_success']) !== '') {
            $messages[] = ['type' => 'success', 'message' => trim($_SESSION['flash_success'])];
        }
        unset($_SESSION['flash_success']);

        if (isset($_SESSION['flash_error']) && is_string($_SESSION['flash_error']) && trim($_SESSION['flash_error']) !== '') {
            $messages[] = ['type' => 'error', 'message' => trim($_SESSION['flash_error'])];
        }
        unset($_SESSION['flash_error']);

        return $messages;
    }
}

if (!function_exists('bvsr_query_all')) {
    function bvsr_query_all(string $sql, array $params = []): array
    {
        if (function_exists('bv_order_refund_query_all')) {
            return bv_order_refund_query_all($sql, $params);
        }

        $db = bvsr_db();
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('PDO prepare failed.');
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        }

        throw new RuntimeException('Unsupported DB adapter for query helper.');
    }
}

if (!function_exists('bvsr_query_one')) {
    function bvsr_query_one(string $sql, array $params = []): ?array
    {
        $rows = bvsr_query_all($sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bvsr_filters_from_get')) {
    function bvsr_filters_from_get(): array
    {
        $allowedStatuses = [
            '',
            'pending_approval',
            'approved',
            'processing',
            'partially_refunded',
            'refunded',
            'rejected',
            'failed',
            'cancelled',
        ];

        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $keyword = mb_substr($keyword, 0, 120);

        $dateFrom = trim((string)($_GET['date_from'] ?? ''));
        $dateTo = trim((string)($_GET['date_to'] ?? ''));

        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = '';
        }
        if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = '';
        }

        $view = strtolower(trim((string)($_GET['view'] ?? 'default')));
        if (!in_array($view, ['default', 'pending', 'all'], true)) {
            $view = 'default';
        }

        return [
            'status' => $status,
            'keyword' => $keyword,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'view' => $view,
        ];
    }
}

if (!function_exists('bvsr_ownership_exists_condition')) {
function bvsr_ownership_exists_condition(): string
{
 return 'EXISTS (
        SELECT 1
        FROM order_refund_items ri0
        INNER JOIN order_items oi0 ON oi0.id = ri0.order_item_id
        INNER JOIN listings l0 ON l0.id = oi0.listing_id
        WHERE ri0.refund_id = r.id
          AND l0.seller_id = :seller_id
    )';
}
}

if (!function_exists('bvsr_refund_item_amount_expr')) {
    function bvsr_refund_item_amount_expr(string $alias, string $type): string
    {
        $candidates = $type === 'approved'
            ? ['approved_refund_amount', 'approved_amount', 'refund_approved_amount']
            : ['requested_refund_amount', 'requested_amount', 'refund_requested_amount', 'refund_amount'];

        $available = [];
        foreach ($candidates as $column) {
            if (function_exists('bv_order_refund_column_exists') && bv_order_refund_column_exists('order_refund_items', $column)) {
                $available[] = $alias . '.' . $column;
            }
        }

        if ($available === []) {
            return '0';
        }

        return 'COALESCE(' . implode(', ', $available) . ', 0)';
    }
}

if (!function_exists('bvsr_refund_has_seller_items')) {
    function bvsr_refund_has_seller_items(array $row): bool
    {
        return (int)($row['seller_item_count'] ?? 0) > 0;
    }
}

if (!function_exists('bvsr_apply_filter_sql')) {
    function bvsr_apply_filter_sql(array $filters, array &$params): string
    {
        $clauses = [];

        if (($filters['view'] ?? 'default') === 'pending' && ($filters['status'] ?? '') === '') {
            $clauses[] = "r.status = 'pending_approval'";
        }

        if (($filters['status'] ?? '') !== '') {
            $clauses[] = 'r.status = :status';
            $params['status'] = (string)$filters['status'];
        }

        if (($filters['keyword'] ?? '') !== '') {
$clauses[] = '(
    r.refund_code LIKE :kw
    OR COALESCE(o.order_code, \'\') LIKE :kw
    OR COALESCE(r.refund_reason_text, \'\') LIKE :kw
)';
            $params['kw'] = '%' . (string)$filters['keyword'] . '%';
        }

        if (($filters['date_from'] ?? '') !== '') {
            $clauses[] = 'COALESCE(r.requested_at, r.created_at) >= :date_from';
            $params['date_from'] = (string)$filters['date_from'] . ' 00:00:00';
        }

        if (($filters['date_to'] ?? '') !== '') {
            $clauses[] = 'COALESCE(r.requested_at, r.created_at) <= :date_to';
            $params['date_to'] = (string)$filters['date_to'] . ' 23:59:59';
        }

        return $clauses === [] ? '' : (' AND ' . implode(' AND ', $clauses));
    }
}

if (!function_exists('bvsr_fetch_summary_counts')) {
    function bvsr_fetch_summary_counts(int $sellerId, array $filters = []): array
    {
        if ($sellerId <= 0) {
            return [];
        }

        $params = [
    'seller_id' => $sellerId,
 
];
        $filterSql = bvsr_apply_filter_sql($filters, $params);

        $sql = 'SELECT r.status, COUNT(*) AS total
                  FROM order_refunds r
             LEFT JOIN orders o ON o.id = r.order_id
                 WHERE ' . bvsr_ownership_exists_condition() . $filterSql . '
              GROUP BY r.status';

        $rows = bvsr_query_all($sql, $params);
        $counts = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['status'] ?? 'unknown')));
            $counts[$status] = (int)($row['total'] ?? 0);
        }

        return $counts;
    }
}

if (!function_exists('bvsr_fetch_refunds')) {
    function bvsr_fetch_refunds(int $sellerId, array $filters = []): array
    {
        if ($sellerId <= 0) {
            return [];
        }

$params = [
    'seller_id' => $sellerId,

];
        $filterSql = bvsr_apply_filter_sql($filters, $params);

$requestedExpr = bvsr_refund_item_amount_expr('ri', 'requested');
        $approvedExpr = bvsr_refund_item_amount_expr('ri', 'approved');

        $titleExpr = 'l.title';
        if (function_exists('bv_order_refund_column_exists') && bv_order_refund_column_exists('order_items', 'title_snapshot')) {
            $titleExpr = 'COALESCE(oi.title_snapshot, l.title)';
        } elseif (function_exists('bv_order_refund_column_exists') && bv_order_refund_column_exists('order_items', 'item_title')) {
            $titleExpr = 'COALESCE(oi.item_title, l.title)';
        }

$sql = 'SELECT
            r.id,
            r.refund_code,
            r.order_id,
            r.status,
            r.currency,
            COALESCE(SUM(' . $requestedExpr . '), 0) AS seller_requested_refund_amount,
            COALESCE(SUM(' . $approvedExpr . '), 0) AS seller_approved_refund_amount,
            r.actual_refunded_amount,
            r.requested_at,
            r.created_at,
            r.updated_at,
            r.refund_reason_code,
            r.refund_reason_text,
            o.order_code AS order_code,
            COUNT(DISTINCT ri.id) AS seller_item_count,
           GROUP_CONCAT(DISTINCT ' . $titleExpr . ' ORDER BY oi.id ASC SEPARATOR \' || \') AS listing_titles 
        FROM order_refunds r
   LEFT JOIN orders o ON o.id = r.order_id
  INNER JOIN order_refund_items ri ON ri.refund_id = r.id
  INNER JOIN order_items oi ON oi.id = ri.order_item_id
  INNER JOIN listings l ON l.id = oi.listing_id AND l.seller_id = :seller_id
       WHERE ' . bvsr_ownership_exists_condition() . $filterSql . '
    GROUP BY r.id
    HAVING COUNT(DISTINCT ri.id) > 0
    ORDER BY
        FIELD(r.status,
            \'pending_approval\',
            \'approved\',
            \'processing\',
            \'partially_refunded\',
            \'refunded\',
            \'rejected\',
            \'failed\',
            \'cancelled\'
        ),
        COALESCE(r.requested_at, r.created_at) DESC,
        r.id DESC
    LIMIT 500';

        return bvsr_query_all($sql, $params);
    }
}

if (!function_exists('bvsr_listing_summary_for_rows')) {
    function bvsr_listing_summary_for_rows(array $row): string
    {
        $raw = trim((string)($row['listing_titles'] ?? ''));
        if ($raw !== '') {
            $parts = array_values(array_filter(array_unique(array_map('trim', explode('||', $raw))), static function ($v): bool {
                return $v !== '';
            }));
            if ($parts !== []) {
                if (count($parts) === 1) {
                    return $parts[0];
                }

                $first = $parts[0];
                $rest = count($parts) - 1;
                return $first . ' +' . $rest . ' more';
            }
        }

        return 'Listing / item not available';
    }
}

if (!function_exists('bvsr_pick')) {
    function bvsr_pick(array $row, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    }
}

if (!function_exists('bvsr_flash_class')) {
    function bvsr_flash_class(string $type): string
    {
        $type = strtolower(trim($type));
        if (in_array($type, ['error', 'danger'], true)) {
            return 'danger';
        }
        if ($type === 'warning') {
            return 'warning';
        }
        if ($type === 'success') {
            return 'success';
        }
        return 'info';
    }
}

if (!function_exists('bvsr_format_time')) {
    function bvsr_format_time($value): string
    {
        $text = trim((string)$value);
        return $text !== '' ? $text : '-';
    }
}

$flashMessages = bvsr_consume_flash();
$userId = bvsr_current_user_id();
$role = bvsr_current_user_role();

if ($userId <= 0 || !bvsr_is_seller()) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6fb;padding:40px;color:#1f2937}
        .box{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px}
        h1{margin-top:0;font-size:22px}
    </style>
</head>
<body>
<div class="box">
    <h1>403 - Seller Access Required</h1>
    <p>You do not have permission to view this page.</p>
</div>
</body>
</html><?php
    exit;
}

$filters = bvsr_filters_from_get();
$summaryCounts = [];
$rows = [];
$errorMessage = '';

try {
    $summaryCounts = bvsr_fetch_summary_counts($userId, $filters);
    $rows = bvsr_fetch_refunds($userId, $filters);
} catch (Throwable $e) {
    $errorMessage = 'Unable to load refund queue at the moment.';
}

$statusCards = [
    'pending_approval' => (int)($summaryCounts['pending_approval'] ?? 0),
    'approved' => (int)($summaryCounts['approved'] ?? 0),
    'processing' => (int)($summaryCounts['processing'] ?? 0),
    'refunded' => (int)($summaryCounts['refunded'] ?? 0),
    'rejected_failed' => (int)($summaryCounts['rejected'] ?? 0) + (int)($summaryCounts['failed'] ?? 0),
];

$csrfToken = bvsr_csrf_token();
$currentUrl = bvsr_build_url('/seller/refunds.php', $filters);
if (!bvsr_is_safe_return_url($currentUrl)) {
    $currentUrl = '/seller/refunds.php';
}
$resetUrl = '/seller/refunds.php';
$dashboardUrl = '/seller/dashboard.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Refund Requests</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:24px;background:#f4f6fb;font-family:Arial,sans-serif;color:#1f2937}
        .container{max-width:1320px;margin:0 auto}
        .header{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        h1{margin:0;font-size:28px}
        .subtitle{margin-top:6px;color:#6b7280}
        .top-links{display:flex;gap:10px;flex-wrap:wrap}
        .top-links a{display:inline-block;padding:9px 12px;background:#fff;border:1px solid #d1d5db;border-radius:8px;text-decoration:none;color:#1f2937;font-size:14px}
        .meta{color:#6b7280;font-size:13px}
        .flash{padding:12px 14px;border-radius:8px;margin-bottom:12px;border:1px solid transparent}
        .flash.success{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .flash.danger{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .flash.warning{background:#fffbeb;border-color:#fde68a;color:#92400e}
        .flash.info{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
        .cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
        .card .label{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.2px}
        .card .value{margin-top:6px;font-size:24px;font-weight:700}
        .panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
        .filters{display:grid;grid-template-columns:1.1fr 1.2fr 1fr 1fr 1fr auto auto;gap:10px;align-items:end}
        .field label{display:block;font-size:12px;color:#6b7280;margin-bottom:5px}
        .field input,.field select{width:100%;padding:9px;border:1px solid #d1d5db;border-radius:8px;font:inherit;background:#fff}
        .btn{display:inline-block;padding:10px 12px;border:0;border-radius:8px;font-weight:700;cursor:pointer;text-decoration:none;text-align:center}
        .btn.primary{background:#2563eb;color:#fff}
        .btn.ghost{background:#fff;border:1px solid #d1d5db;color:#1f2937}
        .btn.small{padding:7px 10px;font-size:12px}
        .btn.approve{background:#16a34a;color:#fff}
        .btn.reject{background:#dc2626;color:#fff}
        .table-wrap{overflow:auto}
        table{width:100%;border-collapse:collapse;min-width:1050px}
        th,td{border-bottom:1px solid #eef2f7;padding:10px 8px;text-align:left;vertical-align:top}
        th{font-size:12px;text-transform:uppercase;letter-spacing:.3px;color:#374151;background:#f8fafc}
        td{font-size:14px}
        .badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.2px;text-transform:uppercase}
        .badge.success{background:#dcfce7;color:#166534}
        .badge.warning{background:#fef3c7;color:#92400e}
        .badge.danger{background:#fee2e2;color:#991b1b}
        .badge.info{background:#dbeafe;color:#1d4ed8}
        .badge.muted{background:#e5e7eb;color:#374151}
        .muted{color:#6b7280}
        .stack{display:flex;flex-direction:column;gap:6px}
        .quick-form{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px}
        .quick-form input[type="number"]{width:120px;padding:6px 7px;border:1px solid #d1d5db;border-radius:6px}
        @media (max-width:1200px){.cards{grid-template-columns:repeat(3,minmax(0,1fr))}.filters{grid-template-columns:1fr 1fr 1fr 1fr}}
        @media (max-width:760px){body{padding:14px}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:1fr 1fr}.top-links{width:100%}}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Seller Refund Requests</h1>
          <div class="subtitle">Review refund requests for items from your listings only. You will only see refund items linked to your own listings. Cancel first, refund second.</div> 
            <div class="meta">Seller ID: <?php echo bvsr_h($userId); ?> · Role: <?php echo bvsr_h($role !== '' ? $role : 'seller'); ?></div>
        </div>
        <div class="top-links">
            <a href="<?php echo bvsr_h($dashboardUrl); ?>">Seller Dashboard</a>
            <a href="<?php echo bvsr_h($resetUrl); ?>">Reset / Refresh</a>
        </div>
    </div>

    <?php foreach ($flashMessages as $flash): ?>
        <div class="flash <?php echo bvsr_h(bvsr_flash_class((string)($flash['type'] ?? 'info'))); ?>">
            <?php echo bvsr_h((string)($flash['message'] ?? '')); ?>
        </div>
    <?php endforeach; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="flash danger"><?php echo bvsr_h($errorMessage); ?></div>
    <?php endif; ?>

    <div class="cards">
        <div class="card"><div class="label">Pending Approval</div><div class="value"><?php echo bvsr_h($statusCards['pending_approval']); ?></div></div>
        <div class="card"><div class="label">Approved</div><div class="value"><?php echo bvsr_h($statusCards['approved']); ?></div></div>
        <div class="card"><div class="label">Processing</div><div class="value"><?php echo bvsr_h($statusCards['processing']); ?></div></div>
        <div class="card"><div class="label">Refunded</div><div class="value"><?php echo bvsr_h($statusCards['refunded']); ?></div></div>
        <div class="card"><div class="label">Rejected / Failed</div><div class="value"><?php echo bvsr_h($statusCards['rejected_failed']); ?></div></div>
    </div>

    <div class="panel">
        <form method="get" action="/seller/refunds.php" class="filters">
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php
                    $statusOptions = [
                        '' => 'All statuses',
                        'pending_approval' => 'Pending approval',
                        'approved' => 'Approved',
                        'processing' => 'Processing',
                        'partially_refunded' => 'Partially refunded',
                        'refunded' => 'Refunded',
                        'rejected' => 'Rejected',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ];
                    foreach ($statusOptions as $value => $label):
                        ?>
                        <option value="<?php echo bvsr_h($value); ?>" <?php echo ((string)$filters['status'] === (string)$value) ? 'selected' : ''; ?>><?php echo bvsr_h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="keyword">Keyword</label>
                <input id="keyword" name="keyword" type="text" value="<?php echo bvsr_h($filters['keyword']); ?>" placeholder="refund code, order code, reason...">
            </div>
            <div class="field">
                <label for="date_from">Date From</label>
                <input id="date_from" name="date_from" type="date" value="<?php echo bvsr_h($filters['date_from']); ?>">
            </div>
            <div class="field">
                <label for="date_to">Date To</label>
                <input id="date_to" name="date_to" type="date" value="<?php echo bvsr_h($filters['date_to']); ?>">
            </div>
            <div class="field">
                <label for="view">View</label>
                <select id="view" name="view">
                    <?php
                    $viewOptions = [
                        'default' => 'Default',
                        'pending' => 'Pending first',
                        'all' => 'All',
                    ];
                    foreach ($viewOptions as $value => $label):
                        ?>
                        <option value="<?php echo bvsr_h($value); ?>" <?php echo ((string)$filters['view'] === (string)$value) ? 'selected' : ''; ?>><?php echo bvsr_h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn primary" type="submit">Apply</button>
            <a class="btn ghost" href="<?php echo bvsr_h($resetUrl); ?>">Reset</a>
        </form>
    </div>

    <div class="panel">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Refund Code</th>
                    <th>Order Code</th>
                    <th>Buyer / Request Context</th>
                    <th>Listing Summary</th>
                    <th>Requested Amount</th>
                    <th>Approved Amount</th>
                    <th>Status</th>
                    <th>Requested At</th>
                    <th>Updated At</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="10" class="muted">No refund requests found for the selected filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $refundId = (int)bvsr_pick($row, ['id'], 0);
                        $refundCode = (string)bvsr_pick($row, ['refund_code'], 'REFUND #' . $refundId);
                        $orderId = (int)bvsr_pick($row, ['order_id'], 0);
                        $orderCode = (string)bvsr_pick($row, ['order_code'], 'ORDER #' . $orderId);
                        $currency = (string)bvsr_pick($row, ['currency'], '');
                        $requestedAmount = bvsr_pick($row, ['seller_requested_refund_amount'], 0);
                        $approvedAmount = bvsr_pick($row, ['seller_approved_refund_amount'], 0);
                        $status = strtolower(trim((string)bvsr_pick($row, ['status'], 'unknown')));
                        $badge = bvsr_refund_status_badge($status);
                        $requestedAt = bvsr_format_time(bvsr_pick($row, ['requested_at', 'created_at'], '-'));
                        $updatedAt = bvsr_format_time(bvsr_pick($row, ['updated_at'], '-'));
$buyerName = '-';
$buyerEmail = '-';

$reasonText = (string)bvsr_pick($row, ['refund_reason_text'], '-');
if (trim($reasonText) === '') {
    $reasonText = '-';
}
                        $listingSummary = bvsr_listing_summary_for_rows($row);
                        $viewUrl = bvsr_build_url('/seller/refund_view.php', ['id' => $refundId]);
                        $canTakeAction = bvsr_refund_has_seller_items($row);
                        ?>
                        <tr>
                            <td>
                                <div class="stack">
                                    <strong><?php echo bvsr_h($refundCode); ?></strong>
                                    <span class="muted">ID: <?php echo bvsr_h($refundId); ?></span>
                                </div>
                            </td>
                            <td><?php echo bvsr_h($orderCode !== '' ? $orderCode : 'ORDER #' . $orderId); ?></td>
                            <td>
                                <div class="stack">
                                    <span><?php echo bvsr_h($buyerName); ?></span>
                                    <span class="muted"><?php echo bvsr_h($buyerEmail); ?></span>
                                    <span class="muted">Reason: <?php echo bvsr_h($reasonText); ?></span>

                                </div>
                            </td>
                            <td><?php echo bvsr_h($listingSummary); ?></td>
                            <td><?php echo bvsr_h(bvsr_money($requestedAmount, $currency)); ?></td>
                            <td><?php echo bvsr_h(bvsr_money($approvedAmount, $currency)); ?></td>
                            <td><span class="badge <?php echo bvsr_h($badge['class']); ?>"><?php echo bvsr_h($badge['label']); ?></span></td>
                            <td><?php echo bvsr_h($requestedAt); ?></td>
                            <td><?php echo bvsr_h($updatedAt); ?></td>
                            <td>
                                <div class="stack">
                                    <a class="btn small ghost" href="<?php echo bvsr_h($viewUrl); ?>">View</a>
                                    <?php if ($status === 'pending_approval' && $canTakeAction): ?>
                                        <form method="post" action="/seller/refund_action.php" class="quick-form">
                                            <input type="hidden" name="refund_id" value="<?php echo bvsr_h($refundId); ?>">
                                            <input type="hidden" name="return_url" value="<?php echo bvsr_h($currentUrl); ?>">
                                            <?php if ($csrfToken !== ''): ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo bvsr_h($csrfToken); ?>">
                                            <?php endif; ?>
                                            <input type="number" name="approved_refund_amount" min="0" step="0.01" value="<?php echo bvsr_h(number_format((float)(is_numeric($requestedAmount) ? $requestedAmount : 0), 2, '.', '')); ?>" title="Approved amount">
                                            <button class="btn small approve" type="submit" name="action" value="approve">Approve</button>
                                            <button class="btn small reject" type="submit" name="action" value="reject">Reject</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
