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
    __DIR__ . '/order_refund.php',
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

if (!function_exists('bvsra_current_user_id')) {
    function bvsra_current_user_id(): int
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

        foreach (['seller_id', 'user_id', 'member_id', 'auth_user_id', 'id'] as $key) {
            if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
                return (int)$_SESSION[$key];
            }
        }

        $nested = [$_SESSION['user']['id'] ?? null, $_SESSION['member']['id'] ?? null, $_SESSION['auth_user']['id'] ?? null];
        foreach ($nested as $candidate) {
            if (is_numeric($candidate) && (int)$candidate > 0) {
                return (int)$candidate;
            }
        }

        return 0;
    }
}

if (!function_exists('bvsra_current_user_role')) {
    function bvsra_current_user_role(): string
    {
        if (function_exists('bv_order_refund_current_user_role')) {
            $role = strtolower(trim((string)bv_order_refund_current_user_role()));
            if ($role !== '') {
                return $role;
            }
        }

        foreach (['seller_role', 'user_role', 'role', 'account_role'] as $key) {
            if (isset($_SESSION[$key]) && is_string($_SESSION[$key]) && trim($_SESSION[$key]) !== '') {
                return strtolower(trim((string)$_SESSION[$key]));
            }
        }

        $nested = [$_SESSION['user']['role'] ?? null, $_SESSION['member']['role'] ?? null, $_SESSION['auth_user']['role'] ?? null];
        foreach ($nested as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim((string)$candidate));
            }
        }

        return 'guest';
    }
}

if (!function_exists('bvsra_is_seller')) {
    function bvsra_is_seller(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (function_exists('seller_is_approved') && seller_is_approved($userId)) {
            return true;
        }

        return in_array(bvsra_current_user_role(), ['seller', 'vendor', 'merchant', 'shop_owner'], true);
    }
}

if (!function_exists('bvsra_flash')) {
    function bvsra_flash(string $type, string $message): void
    {
        $_SESSION['seller_refund_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('bvsra_safe_return_url')) {
    function bvsra_safe_return_url(string $url, int $refundId = 0): string
    {
        $url = trim($url);
        if ($url === '') {
            return $refundId > 0 ? ('/seller/refund_view.php?id=' . $refundId) : '/seller/refunds.php';
        }

        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) {
            return '/seller/refunds.php';
        }
        if (strpos($url, '//') === 0 || strpos($url, "\n") !== false || strpos($url, "\r") !== false) {
            return '/seller/refunds.php';
        }
        if (stripos($url, 'javascript:') === 0 || stripos($url, 'data:') === 0) {
            return '/seller/refunds.php';
        }

        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($path === '' || strpos($path, '/seller/') !== 0) {
            return '/seller/refunds.php';
        }

        return $url;
    }
}

if (!function_exists('bvsra_verify_csrf')) {
    function bvsra_verify_csrf(?string $incomingToken): bool
    {
        $incomingToken = is_string($incomingToken) ? trim($incomingToken) : '';
        $primaryToken = $_SESSION['_csrf_seller_refunds']['refund_actions'] ?? '';

        if (is_string($primaryToken) && $primaryToken !== '' && hash_equals($primaryToken, $incomingToken)) {
            return true;
        }

        $legacyPools = [
            $_SESSION['_csrf_seller_refunds']['refund_action'] ?? null,
            $_SESSION['_csrf_seller_refunds']['actions'] ?? null,
            $_SESSION['csrf_seller_refunds']['refund_actions'] ?? null,
        ];

        foreach ($legacyPools as $legacyToken) {
            if (is_string($legacyToken) && $legacyToken !== '' && hash_equals($legacyToken, $incomingToken)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('bvsra_seller_owns_refund')) {
    function bvsra_seller_owns_refund(int $refundId, int $sellerId): bool
    {
        if ($refundId <= 0 || $sellerId <= 0) {
            return false;
        }

        if (function_exists('bv_order_refund_get_items_for_seller')) {
            try {
                $items = bv_order_refund_get_items_for_seller($refundId, $sellerId);
                if (is_array($items) && $items !== []) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }

        if (!function_exists('bv_order_refund_query_one')) {
            return false;
        }

        try {
            $row = bv_order_refund_query_one(
                'SELECT COUNT(*) AS c
                 FROM order_refund_items ri
                 INNER JOIN order_items oi ON oi.id = ri.order_item_id
                 INNER JOIN listings l ON l.id = oi.listing_id
                 WHERE ri.refund_id = :refund_id AND l.seller_id = :seller_id',
                ['refund_id' => $refundId, 'seller_id' => $sellerId]
            );
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bvsra_apply_seller_decision')) {
    function bvsra_apply_seller_decision(int $refundId, int $sellerId, string $action, float $approvedAmount, string $note): void
    {
        $action = strtolower(trim($action));

        if (function_exists('bv_order_refund_save_seller_decision')) {
            bv_order_refund_save_seller_decision($refundId, $sellerId, $action, $approvedAmount, $note);
            return;
        }

        if (function_exists('bv_order_refund_set_seller_decision')) {
            bv_order_refund_set_seller_decision($refundId, $sellerId, $action, $approvedAmount, $note);
            return;
        }

        if (function_exists('bv_order_refund_seller_decide')) {
            bv_order_refund_seller_decide($refundId, $sellerId, $action, $approvedAmount, $note);
            return;
        }

        if ($action === 'approve' && function_exists('bv_order_refund_approve_seller_slice')) {
            bv_order_refund_approve_seller_slice($refundId, $sellerId, $approvedAmount, $note);
            return;
        }

        if ($action === 'reject' && function_exists('bv_order_refund_reject_seller_slice')) {
            bv_order_refund_reject_seller_slice($refundId, $sellerId, $note);
            return;
        }

        if (function_exists('bv_order_refund_table_exists') && function_exists('bv_order_refund_execute') && bv_order_refund_table_exists('order_refund_seller_decisions')) {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $now = function_exists('bv_order_refund_now') ? bv_order_refund_now() : date('Y-m-d H:i:s');

            $existing = function_exists('bv_order_refund_query_one')
                ? bv_order_refund_query_one(
                    'SELECT id FROM order_refund_seller_decisions WHERE refund_id = :refund_id AND seller_id = :seller_id ORDER BY id DESC LIMIT 1',
                    ['refund_id' => $refundId, 'seller_id' => $sellerId]
                )
                : null;

            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                bv_order_refund_execute(
                    'UPDATE order_refund_seller_decisions
                     SET status = :status,
                         approved_amount = :approved_amount,
                         note = :note,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'status' => $status,
                        'approved_amount' => $approvedAmount,
                        'note' => $note,
                        'updated_at' => $now,
                        'id' => (int)$existing['id'],
                    ]
                );
                return;
            }

            bv_order_refund_execute(
                'INSERT INTO order_refund_seller_decisions
                (refund_id, seller_id, status, requested_amount, approved_amount, note, decided_at, created_at, updated_at)
                 VALUES
                (:refund_id, :seller_id, :status, 0, :approved_amount, :note, :decided_at, :created_at, :updated_at)',
                [
                    'refund_id' => $refundId,
                    'seller_id' => $sellerId,
                    'status' => $status,
                    'approved_amount' => $approvedAmount,
                    'note' => $note,
                    'decided_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            return;
        }

        throw new RuntimeException('Seller decision handler is unavailable.');
    }
}

$userId = bvsra_current_user_id();
$refundId = (int)($_POST['refund_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));
$approvedAmount = (float)($_POST['approved_refund_amount'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));
$returnUrl = bvsra_safe_return_url((string)($_POST['return_url'] ?? ''), $refundId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bvsra_flash('danger', 'Invalid request method.');
    header('Location: ' . $returnUrl);
    exit;
}

if ($userId <= 0 || !bvsra_is_seller($userId)) {
    bvsra_flash('danger', 'Seller access required.');
    header('Location: /seller/refunds.php');
    exit;
}

if (!in_array($action, ['approve', 'reject'], true) || $refundId <= 0) {
    bvsra_flash('danger', 'Invalid refund action payload.');
    header('Location: ' . $returnUrl);
    exit;
}

if (!bvsra_verify_csrf($_POST['csrf_token'] ?? '')) {
    bvsra_flash('danger', 'Invalid CSRF token.');
    header('Location: ' . $returnUrl);
    exit;
}

if (!bvsra_seller_owns_refund($refundId, $userId)) {
    bvsra_flash('danger', 'This refund does not belong to your seller listings.');
    header('Location: /seller/refunds.php');
    exit;
}

if ($action === 'approve' && $approvedAmount < 0) {
    bvsra_flash('danger', 'Approved amount must be zero or positive.');
    header('Location: ' . $returnUrl);
    exit;
}

try {
    bvsra_apply_seller_decision($refundId, $userId, $action, $approvedAmount, $note);

    if (function_exists('bv_order_refund_sync_seller_decisions')) {
        bv_order_refund_sync_seller_decisions($refundId);
    }

    bvsra_flash('success', $action === 'approve' ? 'Seller refund slice approved.' : 'Seller refund slice rejected.');
} catch (Throwable $e) {
    error_log('SELLER_REFUND_ACTION_ERROR: ' . $e->getMessage());
    bvsra_flash('danger', 'Unable to save seller refund decision right now.');
}

header('Location: ' . $returnUrl);
exit;
