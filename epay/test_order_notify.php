<?php
/**
 * 支付回调自测脚本 - 模拟易支付 POST 通知 xsg_game_notify.php
 *
 * 用法: cd /path/to/epay && php test_order_notify.php
 *
 * 测试说明:
 *   1. 模拟支付成功通知（trade_status=TRADE_SUCCESS）
 *   2. 验证签名与参数解析
 *   3. 检查转发到 Center /order 的响应
 *   4. 验证 charge 表是否写入记录
 *
 * 前置条件: 需要已知商户 key 和正确的 param 数据结构
 */

// 请根据实际情况修改以下常量
define('NOTIFY_URL', 'http://127.0.0.1/epay/xsg_game_notify.php');
define('CENTER_ORDER_URL', 'http://127.0.0.1:8888/order');
define('TEST_MERCHANT_KEY', 'f31ea3156f06eec53b0d5b580fb96db7'); // 商户密钥，需与 pre_user.key 一致

// 测试用的订单数据（请替换为实际测试数据）
$testData = [
    'pid'           => '1000',          // 商户 PID
    'out_trade_no'  => 'xsg' . date('YmdHis') . '99999_test',
    'trade_status'  => 'TRADE_SUCCESS',
    'param'         => json_encode([
        'v'       => 1,
        'areaid'  => 1,                 // show_id，需与 game_server.show_id 一致
        'account' => 'test_player_001',
        'channel' => 0,
        'pm_id'   => 0,
        'cardTypeCombine' => 0,
        'role_id' => 'test_role_001',
        'money_cent' => 100,             // 1元（分）
        // expand 须为 CustomChargeParams JSON：item=ChargeItemT.id，与 xsg_unity_epay 下单一致
        'expand' => json_encode(['item' => 1, 'mac' => ''], JSON_UNESCAPED_UNICODE),
    ], JSON_UNESCAPED_UNICODE),
];

// 生成签名（与 lib\Payment::makeSign 一致）
function makeSign($params, $key) {
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $k !== 'sign' && $k !== 'sign_type') {
            $str .= "$k=$v&";
        }
    }
    $str = rtrim($str, '&') . $key;
    return strtolower(md5($str));
}

$testData['sign'] = makeSign($testData, TEST_MERCHANT_KEY);
$testData['sign_type'] = 'MD5';

echo "========== 支付回调自测 ==========\n";
echo "时间: " . date('Y-m-d H:i:s') . "\n";
echo "测试订单号: {$testData['out_trade_no']}\n\n";

// Step 1: 模拟 POST 通知到 xsg_game_notify.php
echo "[Step 1] 模拟 POST 通知 xsg_game_notify.php\n";
$ch = curl_init(NOTIFY_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($testData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

echo "  通知 URL: " . NOTIFY_URL . "\n";
echo "  HTTP 状态: $httpCode\n";
echo "  响应内容: $resp\n";
echo "  curl 错误: $curlErr\n\n";

$notifyOk = ($httpCode === 200 && trim($resp) === 'success');
echo "  结果: " . ($notifyOk ? 'PASS' : 'FAIL') . "\n\n";

// Step 2: 直接测试 Center /order 端点（独立订单号，避免与 Step 1 重复）
echo "[Step 2] 直接测试 Center /order 端点\n";
$directOrderId = $testData['out_trade_no'] . '_direct';
$orderParams = [
    'areaid'          => '1',
    'account'         => 'test_player_001',
    'money'           => '100',
    'orderid'         => $directOrderId,
    'pm_id'           => '0',
    'cardTypeCombine' => '0',
    'channel'         => '0',
    'role_id'         => 'test_role_001',
    'expand'          => json_encode(['item' => 1, 'mac' => ''], JSON_UNESCAPED_UNICODE),
    'sign'            => '',
];

$ch2 = curl_init(CENTER_ORDER_URL);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query($orderParams));
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
$resp2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$curlErr2 = curl_error($ch2);
curl_close($ch2);

echo "  端点 URL: " . CENTER_ORDER_URL . "\n";
echo "  HTTP 状态: $httpCode2\n";
echo "  响应内容: $resp2\n";
echo "  curl 错误: $curlErr2\n\n";

$orderOk = ($httpCode2 === 200 && strpos($resp2, 'Y') !== false);
echo "  结果: " . ($orderOk ? 'PASS' : 'FAIL') . "\n\n";

// Step 3: 检查 charge 表（需连接数据库）
echo "[Step 3] 检查 charge 表记录\n";
$dsn = 'mysql:host=127.0.0.1;port=3308;dbname=xsg_center;charset=utf8';
try {
    $pdo = new PDO($dsn, 'root', '123456');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("SELECT * FROM charge WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $testData['out_trade_no']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $stmt->execute([':oid' => $directOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($row) {
        echo "  找到记录: orderId={$row['order_id']} state={$row['state']} cent={$row['cent']} account={$row['account']}\n";
        echo "  结果: PASS\n";
    } else {
        echo "  未找到记录（可能是重复检测跳过，或 Center 尚未启动）\n";
        echo "  结果: SKIP\n";
    }
    $pdo = null;
} catch (PDOException $e) {
    echo "  数据库连接失败: " . $e->getMessage() . "\n";
    echo "  结果: SKIP\n";
}

echo "\n========== 测试汇总 ==========\n";
echo "Step 1 (xsg_game_notify.php): " . ($notifyOk ? 'PASS' : 'FAIL') . "\n";
echo "Step 2 (Center /order):       " . ($orderOk ? 'PASS' : 'FAIL') . "\n";
echo "Step 3 (charge 表查询):       见上方\n";
echo "\n如有问题，请检查:\n";
echo "  1. PHP 错误日志: /var/log/php-fpm/error.log 或 nginx error.log\n";
echo "  2. Center 服务日志: 查看 Center 服务控制台输出\n";
echo "  3. 重复测试可修改 out_trade_no 避免订单号重复\n";