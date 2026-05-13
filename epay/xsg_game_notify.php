<?php
/**
 * 易支付异步通知：验签后转发 Center /order（form-urlencoded），响应 success 供平台停止重试。
 * 与 xsg_unity_epay.php 写入订单的 param（JSON v=1）配对。
 */
if (!isset($_SERVER['REQUEST_METHOD']) || !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'fail';
    exit;
}

$_xsg_center_order = getenv('XSG_CENTER_ORDER_URL');
if (($_xsg_center_order === false || $_xsg_center_order === '') && !empty($_SERVER['XSG_CENTER_ORDER_URL'])) {
    $_xsg_center_order = (string) $_SERVER['XSG_CENTER_ORDER_URL'];
}
define('XSG_CENTER_ORDER_URL', ($_xsg_center_order !== false && $_xsg_center_order !== '')
    ? $_xsg_center_order
    : 'http://127.0.0.1:8888/order');

$nosession = true;
$_xsg_common = __DIR__ . '/includes/common.php';
if (!is_file($_xsg_common)) {
    $_xsg_common = dirname(__DIR__) . '/includes/common.php';
}
require $_xsg_common;

header('Content-Type: text/plain; charset=UTF-8');

error_log(sprintf('[xsg_game_notify] REMOTE_ADDR=%s SIGN=%s PID=%s OUT_TRADE_NO=%s TRADE_STATUS=%s',
    $_SERVER['REMOTE_ADDR'] ?? '-',
    isset($_GET['sign']) ? substr($_GET['sign'], 0, 16) . '...' : '-',
    $_GET['pid'] ?? $_POST['pid'] ?? '-',
    $_GET['out_trade_no'] ?? $_POST['out_trade_no'] ?? '-',
    $_GET['trade_status'] ?? $_POST['trade_status'] ?? '-'
));

$params = array_merge($_GET, $_POST);
if (empty($params['sign']) || empty($params['pid']) || empty($params['out_trade_no'])) {
    echo 'fail';
    exit;
}

$pid = (int) $params['pid'];
$userrow = $DB->getRow("SELECT `key`,`publickey` FROM `pre_user` WHERE `uid`='{$pid}' LIMIT 1");
if (!$userrow || empty($userrow['key'])) {
    echo 'fail';
    exit;
}

try {
    $pub = isset($userrow['publickey']) ? (string) $userrow['publickey'] : '';
    if (!\lib\Payment::verifySign($params, $userrow['key'], $pub)) {
        echo 'sign_err';
        exit;
    }
} catch (\Exception $e) {
    echo 'sign_err';
    exit;
}

if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
    echo 'fail';
    exit;
}

$innerRaw = isset($params['param']) ? (string) $params['param'] : '';
// 易支付可能对 param 中的引号做双重转义（\&quot; → "），先还原
$innerRaw = str_replace('\&quot;', '"', $innerRaw);
$inner = json_decode($innerRaw, true);
if (!is_array($inner) || (int) ($inner['v'] ?? 0) !== 1) {
    echo 'param';
    exit;
}
if ((int) ($inner['money_cent'] ?? 0) <= 0 || trim((string) ($inner['role_id'] ?? '')) === '') {
    echo 'param';
    exit;
}

$forward = [
    'areaid'            => (string) (int) ($inner['areaid'] ?? 0),
    'account'           => (string) ($inner['account'] ?? ''),
    'money'             => (string) (int) ($inner['money_cent'] ?? 0),
    'orderid'           => (string) ($params['out_trade_no'] ?? ''),
    'pm_id'             => (string) (int) ($inner['pm_id'] ?? 0),
    'cardTypeCombine'   => (string) (int) ($inner['cardTypeCombine'] ?? 0),
    'channel'           => (string) (int) ($inner['channel'] ?? 0),
    'role_id'           => (string) ($inner['role_id'] ?? ''),
    'expand'            => (string) ($inner['expand'] ?? ''),
    'sign'              => '',
];

// areaid 来自客户端 show_id，需映射为 game_server.id（Center 用此 key 查 ICE 代理）
$showId = (int) $forward['areaid'];
if ($showId > 0) {
    try {
        $xsgDb = new PDO('mysql:host=127.0.0.1;port=3308;dbname=xsg_center;charset=utf8', 'root', '123456');
        $gsStmt = $xsgDb->prepare("SELECT id FROM game_server WHERE show_id = :sid LIMIT 1");
        $gsStmt->execute([':sid' => $showId]);
        $gsRow = $gsStmt->fetch(PDO::FETCH_ASSOC);
        if ($gsRow) {
            $forward['areaid'] = (string) (int) $gsRow['id'];
        }
        $xsgDb = null;
    } catch (Exception $e) {
        // 映射失败时保持原 areaid
    }
}

$body = http_build_query($forward);
error_log('[xsg_game_notify] forward body: ' . $body);
$ch = curl_init(XSG_CENTER_ORDER_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 45);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$resp = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);
error_log(sprintf('[xsg_game_notify] orderId=%s resp_code=%d resp_body=%s curl_err=%s',
    $params['out_trade_no'] ?? '-', $httpCode, substr($resp ?: '', 0, 100), $curlErr));

if ($resp !== false && $httpCode === 200 && strpos($resp, 'Y') !== false) {
    echo 'success';
    exit;
}

echo 'center_err';
