<?php
/**
 * 三国 Unity 客户端 → xsanguo-server/server/epay（mapi.php / lib\api\Pay::create）桥接。
 *
 * 请求：POST，Content-Type: application/json，Body 为客户端 PayParams.toJsonString()。
 * 请求头：X-Xsg-AppKey 与游戏 AppConfig.AppKey 一致（与 AppConfig.cs 中配置相同）。
 *
 * 响应：{"code":0,"payUrl":"https://..."} 供 Unity EpayOrderClient 打开。
 *
 * 部署后请修改下方常量；支付成功后由 notify_url（xsg_game_notify.php）转发 Center 写 charge，
 * param.expand 为 CustomChargeParams JSON（item=ChargeItemT.id、mac、roles），由数字 productId 或 extension JSON 合并生成。
 */
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['code' => -1, 'msg' => '仅支持 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ 按环境修改 ============
define('XSG_UNITY_APP_KEY', '1bc2541135f5c96efd5341907ab51f3d');
define('XSG_EPAY_PID', 1000);
define('XSG_EPAY_MERCHANT_KEY', 'f31ea3156f06eec53b0d5b580fb96db7');
define('XSG_EPAY_TYPE', 'alipay');
define('XSG_PAY_NOTIFY_URL', 'http://120.24.112.113/epay/xsg_game_notify.php');
define('XSG_PAY_RETURN_URL', 'http://120.24.112.113/paypage/xsg_success.php');
// 说明：notify/return 须与 Passport 或你方实际回调路径一致，并在易支付后台「支付域名白名单」中放行对应 Host。
// 签名优先用 XSG_EPAY_MERCHANT_KEY；若留空则使用数据库 pre_user.key（须与商户 PID 一致）。
// ====================================

$nosession = true;
$_xsg_common = __DIR__ . '/includes/common.php';
if (!is_file($_xsg_common)) {
    $_xsg_common = dirname(__DIR__) . '/includes/common.php';
}
require $_xsg_common;

/**
 * 生成 charge.params 用 JSON，供 Center 侧 Gson 解析为 CustomChargeParams（字段 item、mac、roles）。
 * item 须为游戏 ChargeItemT 配置 id；优先 extension 内 JSON，否则用 productId（数字）。
 */
function xsg_normalize_charge_params_json(string $extension, string $productId): string
{
    $item = 0;
    if ($productId !== '' && ctype_digit((string) $productId)) {
        $item = (int) $productId;
    }
    $ext = trim($extension);
    if ($ext !== '' && isset($ext[0]) && $ext[0] === '{') {
        $dec = json_decode($ext, true);
        if (is_array($dec) && json_last_error() === JSON_ERROR_NONE) {
            if (!isset($dec['item'])) {
                if ($item > 0) {
                    $dec['item'] = $item;
                } elseif (isset($dec['id']) && ctype_digit((string) $dec['id'])) {
                    $dec['item'] = (int) $dec['id'];
                }
            }
            if (!isset($dec['mac'])) {
                $dec['mac'] = '';
            }
            if (!array_key_exists('roles', $dec)) {
                $dec['roles'] = null;
            }
            return json_encode($dec, JSON_UNESCAPED_UNICODE);
        }
    }
    if ($item <= 0) {
        return '';
    }
    return json_encode(['item' => $item, 'mac' => ''], JSON_UNESCAPED_UNICODE);
}

function xsg_req_header($name)
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
    }
    $alt = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$alt]) ? (string) $_SERVER[$alt] : '';
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    header('Content-Type: application/json; charset=UTF-8');
    error_log('[xsg_unity_epay] invalid JSON input: ' . substr($raw, 0, 200));
    echo json_encode(['code' => -1, 'msg' => 'JSON 无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (xsg_req_header('X-Xsg-AppKey') !== XSG_UNITY_APP_KEY) {
    error_log('[xsg_unity_epay] auth failed, got key: ' . xsg_req_header('X-Xsg-AppKey'));
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['code' => -403, 'msg' => '鉴权失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pid = (int) XSG_EPAY_PID;
if ($pid <= 0) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['code' => -1, 'msg' => '未配置 XSG_EPAY_PID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userrow = $DB->getRow("SELECT `uid`,`gid`,`key`,`money`,`mode`,`pay`,`cert`,`status`,`channelinfo`,`qq`,`ordername`,`keytype`,`publickey`,`deposit`,`pay_minmoney`,`pay_maxmoney` FROM `pre_user` WHERE `uid`='{$pid}' LIMIT 1");
if (!$userrow) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['code' => -1, 'msg' => '易支付商户不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roleId = isset($payload['roleId']) ? preg_replace('/[^a-zA-Z0-9._\-]/', '', (string) $payload['roleId']) : '';
$productId = isset($payload['productId']) ? preg_replace('/[^a-zA-Z0-9._\-]/', '', (string) $payload['productId']) : '0';
$price = isset($payload['price']) ? $payload['price'] : 0;
$money = is_numeric($price) ? round((float) $price, 2) : 0;
if ($money <= 0) {
    header('Content-Type: application/json; charset=UTF-8');
    error_log('[xsg_unity_epay] invalid money: price=' . $price);
    echo json_encode(['code' => -1, 'msg' => '金额无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out_trade_no = 'xsg' . date('YmdHis') . mt_rand(10000, 99999) . ($roleId !== '' ? '_' . substr($roleId, 0, 32) : '');
$name = isset($payload['productFullName']) ? (string) $payload['productFullName'] : (isset($payload['productName']) ? (string) $payload['productName'] : 'GameCharge');
if (strlen($name) > 120) {
    $name = mb_strcut($name, 0, 120, 'UTF-8');
}

$expandRaw = isset($payload['extension']) ? (string) $payload['extension'] : '';
$expand = xsg_normalize_charge_params_json($expandRaw, $productId);
if ($expand === '') {
    header('Content-Type: application/json; charset=UTF-8');
    error_log('[xsg_unity_epay] missing charge item: need numeric productId (ChargeItemT.id) or extension JSON with item');
    echo json_encode(['code' => -1, 'msg' => '缺少充值档位：请传数字 productId（与游戏 ChargeItemT.id 一致），或在 extension 中提供含 item 的 JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}
$serverId = isset($payload['serverId']) ? (int) preg_replace('/\D/', '', (string) $payload['serverId']) : 0;
$username = isset($payload['username']) ? mb_substr((string) $payload['username'], 0, 64) : '';
$channelNum = isset($payload['channel']) ? (int) $payload['channel'] : 0;
$moneyCent = isset($payload['moneyCent']) ? (int) $payload['moneyCent'] : (int) round($money * 100);
$xsgMeta = [
    'v'              => 1,
    'areaid'         => $serverId,
    'account'        => $username,
    'channel'        => $channelNum,
    'pm_id'          => 0,
    'cardTypeCombine'=> 0,
    'role_id'        => $roleId,
    'money_cent'     => $moneyCent,
    'expand'         => $expand,
];
$param = json_encode($xsgMeta, JSON_UNESCAPED_UNICODE);
while (strlen($param) > 500 && $expandRaw !== '') {
    $expandRaw = mb_strcut($expandRaw, 0, max(1, strlen($expandRaw) - 50), 'UTF-8');
    $expand = xsg_normalize_charge_params_json($expandRaw, $productId);
    if ($expand === '') {
        break;
    }
    $xsgMeta['expand'] = $expand;
    $param = json_encode($xsgMeta, JSON_UNESCAPED_UNICODE);
}
if (strlen($param) > 500) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['code' => -1, 'msg' => '易支付 param 限 500 字节，请缩短 extension'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1');
if (!filter_var($clientip, FILTER_VALIDATE_IP)) {
    $clientip = '127.0.0.1';
}

$notify_url = XSG_PAY_NOTIFY_URL;
$return_url = XSG_PAY_RETURN_URL;
if (empty($notify_url) || !function_exists('is_url') || !is_url($notify_url)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['code' => -1, 'msg' => '请在 xsg_unity_epay.php 中配置合法的 XSG_PAY_NOTIFY_URL'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($return_url) || !is_url($return_url)) {
    $return_url = $notify_url;
}

$post = [
    'pid'          => (string) $pid,
    'type'         => XSG_EPAY_TYPE,
    'out_trade_no' => $out_trade_no,
    'notify_url'   => $notify_url,
    'return_url'   => $return_url,
    'name'         => $name,
    'money'        => (string) $money,
    'clientip'     => $clientip,
    'device'       => 'pc',
    'method'       => 'jump',
    'sitename'     => 'XSanGo',
    'param'        => $param,
    'sign_type'    => 'MD5',
];
$signKey = (defined('XSG_EPAY_MERCHANT_KEY') && XSG_EPAY_MERCHANT_KEY !== '')
    ? XSG_EPAY_MERCHANT_KEY
    : $userrow['key'];
$post['sign'] = \lib\Payment::makeSign($post, $signKey);

$mapi = rtrim($siteurl, '/') . '/mapi.php';
$ch = curl_init($mapi);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
$resp = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=UTF-8');
if ($resp === false) {
    error_log('[xsg_unity_epay] mapi curl failed: ' . $err . ' out_trade_no=' . $out_trade_no);
    echo json_encode(['code' => -1, 'msg' => '请求 mapi 失败: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$j = json_decode($resp, true);
if (!is_array($j)) {
    error_log('[xsg_unity_epay] mapi non-JSON response: ' . substr($resp, 0, 200) . ' out_trade_no=' . $out_trade_no);
    echo json_encode(['code' => -1, 'msg' => 'mapi 非 JSON: ' . substr($resp, 0, 200)], JSON_UNESCAPED_UNICODE);
    exit;
}

$payUrl = null;
$payRedirectUrl = null;
if (!empty($j['payurl']) && is_string($j['payurl'])) {
    $payUrl = $j['payurl'];
    // payurl（method=jump）本身就是跳转链接，Unity 客户端可打开此链接在浏览器完成支付
    $payRedirectUrl = $j['payurl'];
} elseif (!empty($j['pay_info']) && is_string($j['pay_info']) && (int) ($j['code'] ?? -1) === 0) {
    $payUrl = $j['pay_info'];
} elseif (!empty($j['qrcode']) && is_string($j['qrcode'])) {
    $payUrl = $j['qrcode'];
}

$code = isset($j['code']) ? (int) $j['code'] : -99;
if ($payUrl && ($code === 0 || $code === 1)) {
    error_log('[xsg_unity_epay] order created ok out_trade_no=' . $out_trade_no . ' payUrl=' . $payUrl);
    $resp = ['code' => 0, 'payUrl' => $payUrl, 'trade_no' => $j['trade_no'] ?? null];
    if ($payRedirectUrl !== null) {
        $resp['payRedirectUrl'] = $payRedirectUrl;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

$msg = isset($j['msg']) ? (string) $j['msg'] : '无法解析支付地址';
error_log('[xsg_unity_epay] order failed out_trade_no=' . $out_trade_no . ' msg=' . $msg);
echo json_encode(['code' => -1, 'msg' => $msg, 'raw' => $j], JSON_UNESCAPED_UNICODE);
