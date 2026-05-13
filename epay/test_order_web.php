<?php
/**
 * 支付系统 Web 自测页面
 * 访问地址: http://你的服务器/epay/test_order_web.php
 *
 * 功能:
 *   Step 1: 模拟易支付回调 -> xsg_game_notify.php -> Center /order
 *   Step 2: 直连 Center /order 端点测试
 *   Step 3: 查询 charge 表记录
 *   Step 4: 查询 Nginx/PHP 错误日志（如果有权限）
 *
 * 安全注意: 此页面仅供内网/调试使用，部署后请删除或添加访问限制。
 */
session_start();
define('IN_APP', true);

$BASE_URL  = 'http://127.0.0.1';
$CENTER_URL = 'http://127.0.0.1:8888';
$NOTIFY_URL = $BASE_URL . '/epay/xsg_game_notify.php';

$results = [];
$errors  = [];

// ──────────────────────────────────────────
// 默认测试参数（可页面修改）
// ──────────────────────────────────────────
$def_pid          = '1000';
$def_out_trade_no = 'xsg' . date('YmdHis') . 't' . rand(1000, 9999);
$def_areaid       = '1';
$def_account      = 'test_web_user';
$def_role_id      = 'test_web_role';
$def_money_cent   = '100';
$def_expand       = 'web_test';

$pid          = isset($_POST['pid'])          ? $_POST['pid']          : $def_pid;
$out_trade_no = isset($_POST['out_trade_no']) ? $_POST['out_trade_no'] : $def_out_trade_no;
$areaid       = isset($_POST['areaid'])       ? intval($_POST['areaid']) : $def_areaid;
$account      = isset($_POST['account'])      ? trim($_POST['account']) : $def_account;
$role_id      = isset($_POST['role_id'])      ? trim($_POST['role_id']) : $def_role_id;
$money_cent   = isset($_POST['money_cent'])   ? intval($_POST['money_cent']) : $def_money_cent;
$expand       = isset($_POST['expand'])       ? trim($_POST['expand']) : $def_expand;

// ──────────────────────────────────────────
// 辅助函数
// ──────────────────────────────────────────
function hr() { echo "<hr>\n"; }
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function section($title) { echo "<h2>" . h($title) . "</h2>\n"; }
function ok($msg)  { echo "<p style='color:green'>✅ {$msg}</p>\n"; }
function fail($msg) { echo "<p style='color:red'>❌ {$msg}</p>\n"; }
function info($msg) { echo "<p>ℹ {$msg}</p>\n"; }
function code($v)  { echo "<pre>" . h($v) . "</pre>\n"; }

function http_post($url, $data, &$resp_code = null, &$resp_body = null, &$curl_err = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $resp_body = curl_exec($ch);
    $resp_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    return ($resp_code == 200);
}

function make_sign($params, $key) {
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $k !== 'sign' && $k !== 'sign_type') {
            $str .= "$k=$v&";
        }
    }
    return strtolower(md5(rtrim($str, '&') . $key));
}

// ──────────────────────────────────────────
// 执行测试
// ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>支付自测结果</title>";
    echo "<style>body{font-family:monospace;padding:20px;max-width:900px;margin:0 auto;} h1{color:#333;} h2{color:#555;border-bottom:1px solid #ddd;padding-bottom:5px;} pre{background:#f5f5f5;padding:10px;overflow-x:auto;border-radius:4px;} p{margin:5px 0;} hr{margin:20px 0;} a{color:#0066cc;}</style>";
    echo "</head><body>\n";
    echo "<h1>支付系统自测结果</h1>\n";
    echo "<p>测试时间: " . date('Y-m-d H:i:s') . "</p>\n";
    echo "<p><a href='?'>← 重新填写参数</a></p>\n";

    $action = $_POST['action'];

    // ── Step 0: 获取商户密钥
    section("Step 0: 获取商户密钥");
    $nosession = true;
    define('IN_APP', true);
    $config_file = __DIR__ . '/../config.php';
    if (!file_exists($config_file)) {
        fail("config.php not found at: $config_file");
    } else {
        ok("config.php found");
    }

    // ── Step 1: 直接测试 Center /order 端点（如果 Center 已启动）
    if ($action === 'step1_direct' || $action === 'step1_notify') {
        section("Step 1: 直接测试 Center /order 端点");
        $order_data = [
            'areaid'          => (string)$areaid,
            'account'         => $account,
            'money'          => (string)$money_cent,
            'orderid'         => $out_trade_no,
            'pm_id'          => '0',
            'cardTypeCombine' => '0',
            'channel'         => '0',
            'role_id'         => $role_id,
            'expand'          => $expand,
            'sign'            => '',
        ];
        info("POST " . $CENTER_URL . "/order");
        info("请求体: " . http_build_query($order_data));
        $ok = http_post($CENTER_URL . '/order', $order_data, $code, $body, $err);
        echo "<p>HTTP 状态码: $code</p>\n";
        if ($err) echo "<p>curl 错误: " . h($err) . "</p>\n";
        echo "<p>响应内容: <code>" . h($body ?: '(空)') . "</code></p>\n";
        if ($ok && strpos($body, 'Y') !== false) {
            ok("Center /order 端点正常！响应包含 'Y'");
        } elseif (!$ok) {
            fail("无法连接到 Center /order 端点（HTTP $code）");
            info("可能原因: Center 服务未启动，或 8888 端口被防火墙拦截");
            info("请确认 Center 服务已重启并且 OrderHttpHandler 已加载");
        } else {
            fail("Center /order 端点响应异常: " . h($body));
        }
    }

    // ── Step 2: 完整流程测试（xsg_game_notify.php）
    if ($action === 'step1_notify') {
        section("Step 2: 完整回调流程测试（xsg_game_notify.php）");

        // 先获取商户密钥
        // 通过解析 config.php 和 common.php 来连接数据库
        $nosession = true;
        $orig_nosession = true;

        $db_config = null;
        // 直接读取数据库配置
        $config_text = @file_get_contents(__DIR__ . '/../config.php');
        if ($config_text) {
            if (preg_match('/dbname[\"\']\s*,\s*[\"\'](.*?)[\"\']/', $config_text, $m)) {
                info("检测到数据库名: " . h($m[1]));
            }
        }

        // 尝试连接 xsg_center 数据库获取商户密钥
        $merchant_key = null;
        $db_host = '127.0.0.1';
        $db_port = 3308;
        $db_user = 'root';
        $db_pass = '123456';
        $db_name = 'xsg_center';

        try {
            $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->prepare("SELECT `key`,publickey FROM pre_user WHERE uid = :pid LIMIT 1");
            $stmt->execute([':pid' => $pid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['key'])) {
                $merchant_key = $row['key'];
                ok("从 pre_user 表获取到商户密钥（PID=$pid）");
            } else {
                fail("未找到 PID=$pid 的商户密钥，请检查 pre_user 表");
            }
            $pdo = null;
        } catch (PDOException $e) {
            fail("连接 xsg_center 数据库失败: " . h($e->getMessage()));
            info("请在下方「手动提供密钥」输入框填入 pre_user.key 字段");
            $merchant_key = null;
        }

        $param_json = json_encode([
            'v'       => 1,
            'areaid'  => $areaid,
            'account' => $account,
            'channel' => 0,
            'pm_id'   => 0,
            'cardTypeCombine' => 0,
            'role_id' => $role_id,
            'money_cent' => (int)$money_cent,
            'expand'  => $expand,
        ], JSON_UNESCAPED_UNICODE);

        $notify_data = [
            'pid'          => $pid,
            'out_trade_no' => $out_trade_no,
            'trade_status' => 'TRADE_SUCCESS',
            'param'        => $param_json,
        ];

        if ($merchant_key) {
            $notify_data['sign']     = make_sign($notify_data, $merchant_key);
            $notify_data['sign_type'] = 'MD5';
            ok("已生成签名: " . h($notify_data['sign']));
        } else {
            $notify_data['sign']     = 'PLACEHOLDER_KEY_NOT_FOUND';
            $notify_data['sign_type'] = 'MD5';
            info("未找到密钥，签名使用占位符，回调会在验签阶段失败（预期行为）");
        }

        info("POST " . $NOTIFY_URL);
        info("trade_status=TRADE_SUCCESS");
        info("orderId=" . h($out_trade_no));

        $ok = http_post($NOTIFY_URL, $notify_data, $code, $body, $err);
        echo "<p>HTTP 状态码: $code</p>\n";
        if ($err) echo "<p>curl 错误: " . h($err) . "</p>\n";
        echo "<p>响应内容: <code>" . h($body ?: '(空)') . "</code></p>\n";

        if ($body === 'success') {
            ok("xsg_game_notify.php 返回 success，完整流程测试通过！");
        } elseif ($body === 'sign_err') {
            fail("签名验证失败，密钥不匹配");
        } elseif ($body === 'center_err') {
            fail("Center /order 端点调用失败（curl 无法连接或响应不包含 'Y'）");
            info("请先确认 Step 1（Center /order 端点）是否通过");
        } elseif ($body === 'fail') {
            fail("参数校验失败，检查 pid/out_trade_no/sign 是否正确");
        } else {
            info("收到非预期响应: " . h($body));
        }
    }

    // ── Step 3: 查询 charge 表
    if ($action === 'step1_notify' || $action === 'step3_charge') {
        section("Step 3: 查询 charge 表");
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;port=3308;dbname=xsg_center;charset=utf8", 'root', '123456');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->prepare("SELECT order_id,account,server_id,role_id,cent,state,channel,DATE_FORMAT(create_time,'%Y-%m-%d %H:%i:%s') as create_time,DATE_FORMAT(complete_time,'%Y-%m-%d %H:%i:%s') as complete_time FROM charge WHERE order_id = :oid LIMIT 1");
            $stmt->execute([':oid' => $out_trade_no]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                ok("找到 charge 记录！");
                echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>\n";
                foreach ($row as $k => $v) {
                    $state_color = ($k === 'state') ? ($v == 1 ? 'color:green;font-weight:bold' : ($v == 0 ? 'color:#888' : '')) : '';
                    echo "<tr><td><b>" . h($k) . "</b></td><td style='$state_color'>" . h($v ?: '(null)') . "</td></tr>\n";
                }
                echo "</table>\n";
                if ((int)$row['state'] === 1) {
                    info("state=1 表示未处理（Charge 记录已写入，等待游戏服务器轮询处理）");
                    info("游戏服务器处理后 state 会变为 0，元宝随即到账");
                } elseif ((int)$row['state'] === 0) {
                    ok("state=0 表示游戏服务器已处理，元宝应该已到账！");
                }
            } else {
                info("未找到 orderId={$out_trade_no} 的记录");
                info("可能原因: 回调流程失败（sign_err/center_err），或订单号已处理过");
            }
            $pdo = null;
        } catch (PDOException $e) {
            fail("数据库连接失败: " . h($e->getMessage()));
            info("请确认 MySQL 在 127.0.0.1:3308 可访问，用户名密码正确");
        }
    }

    // ── Step 4: 查看 PHP 错误日志
    if ($action === 'step4_log') {
        section("Step 4: 查看 PHP/Nginx 错误日志");
        $log_files = [
            '/var/log/nginx/error.log',
            '/var/log/php-fpm/error.log',
            '/tmp/php_errors.log',
            '/var/log/php_errors.log',
            __DIR__ . '/../logs/app.log',
            __DIR__ . '/../logs/error.log',
            __DIR__ . '/../logs/pay.log',
        ];
        $found_log = false;
        foreach ($log_files as $lf) {
            if (file_exists($lf) && is_readable($lf)) {
                $found_log = true;
                $lines = array_slice(file($lf), -100);
                echo "<h3>$lf (最后100行)</h3>\n";
                echo "<pre style='background:#f8f8f8;max-height:400px;overflow:auto;'>";
                foreach ($lines as $l) {
                    if (stripos($l, 'xsg_game_notify') !== false || stripos($l, 'xsg_unity_epay') !== false || stripos($l, 'order') !== false || stripos($l, 'pay') !== false || stripos($l, 'notify') !== false) {
                        echo h($l);
                    }
                }
                echo "</pre>\n";
            }
        }
        if (!$found_log) {
            info("未找到可读日志文件，请手动检查:");
            foreach ($log_files as $lf) echo "<li>" . h($lf) . "</li>\n";
        }
    }

    echo "<hr>\n";
    echo "<p><a href='?'>← 返回测试页面</a></p>\n";
    echo "</body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>支付系统自测工具</title>
<style>
body { font-family: monospace; padding: 20px; max-width: 900px; margin: 0 auto; background: #f9f9f9; }
h1 { color: #333; }
h2 { color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 30px; }
p { margin: 5px 0; }
label { display: inline-block; width: 140px; font-weight: bold; color: #444; }
input[type=text], input[type=number] { width: 300px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 3px; }
hr { margin: 25px 0; border: none; border-top: 1px solid #ddd; }
.btn { padding: 10px 20px; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; margin: 5px 5px 5px 0; font-family: monospace; }
.btn-primary { background: #0066cc; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-warning { background: #e67e22; color: white; }
.btn-danger  { background: #dc3545; color: white; }
.btn-info    { background: #17a2b8; color: white; }
.note { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin: 10px 0; color: #856404; }
.instructions { background: #e9ecef; padding: 15px; border-radius: 4px; line-height: 1.8; }
.instructions li { margin: 5px 0; }
</style>
</head>
<body>

<h1>支付系统自测工具</h1>

<div class="note">
  <b>⚠️ 安全提示：</b>此页面仅供内网调试使用。测试完成后请删除或添加访问限制。
</div>

<h2>测试参数</h2>
<form method="POST">
  <input type="hidden" name="action" value="step1_direct">

  <p><label for="out_trade_no">订单号:</label>
     <input type="text" name="out_trade_no" id="out_trade_no" value="<?=h($def_out_trade_no)?>"></p>

  <p><label for="pid">商户 PID:</label>
     <input type="number" name="pid" id="pid" value="<?=h($def_pid)?>"></p>

  <p><label for="areaid">服务器 ShowId:</label>
     <input type="number" name="areaid" id="areaid" value="<?=h($def_areaid)?>">（game_server.show_id）</p>

  <p><label for="account">账号名:</label>
     <input type="text" name="account" id="account" value="<?=h($def_account)?>"></p>

  <p><label for="role_id">角色ID:</label>
     <input type="text" name="role_id" id="role_id" value="<?=h($def_role_id)?>"></p>

  <p><label for="money_cent">金额(分):</label>
     <input type="number" name="money_cent" id="money_cent" value="<?=h($def_money_cent)?>">（1元=100分）</p>

  <p><label for="expand">扩展字段:</label>
     <input type="text" name="expand" id="expand" value="<?=h($def_expand)?>"></p>

  <hr>
  <h2>执行测试</h2>

  <p class="note">建议按顺序执行，Step 1 是基础，Step 1 通过后 Step 2 才能成功。</p>

  <button class="btn btn-primary" type="submit">
    ▶ Step 1: 直接测试 Center /order 端点
  </button>

  <button class="btn btn-success" type="button" onclick="runStep2()">
    ▶ Step 2: 完整回调流程（xsg_game_notify.php）
  </button>

  <button class="btn btn-warning" type="button" onclick="runStep3()">
    ▶ Step 3: 查询 charge 表记录
  </button>

  <button class="btn btn-info" type="button" onclick="runStep4()">
    ▶ Step 4: 查看 PHP 错误日志
  </button>

  <button class="btn btn-danger" type="button" onclick="runAll()">
    🚀 完整流程测试（Step 1+2+3）
  </button>
</form>

<h2>使用说明</h2>
<div class="instructions">
  <p><b>Step 1（最重要）:</b> 直接测试 Center /order 端点，验证 OrderHttpHandler 是否正常启动。</p>
  <ul>
    <li>✅ 返回 <code>Y</code> → OrderHttpHandler 启动正常！继续 Step 2</li>
    <li>❌ curl 错误或空响应 → Center 服务未启动 / 8888 端口不通 / 未编译新代码</li>
  </ul>

  <p><b>Step 2:</b> 模拟易支付完整回调流程（包含签名验证），验证从收到回调到写入 charge 表的全链路。</p>

  <p><b>Step 3:</b> 查询 charge 表，确认订单记录已写入，state=1 表示等待处理，state=0 表示已处理。</p>

  <p><b>Step 4:</b> 查看 PHP 错误日志中的 [xsg_game_notify] 和 [xsg_unity_epay] 日志。</p>

  <hr>
  <p><b>部署步骤:</b></p>
  <ol>
    <li>编译 Center: <code>cd XSanGo.Center && javac -encoding UTF-8 -cp "lib/*:lib/spring/*:src" src/com/morefun/XSanGo/http/OrderHttpHandler.java</code></li>
    <li>重新打包 xsango-center.jar（或直接替换 class 文件到 jar 中）</li>
    <li>重启 Center 服务</li>
    <li>在日志中确认看到: <code>OrderHttpHandler started on port 8888 and path /order</code></li>
    <li>访问本页面执行 Step 1 自测</li>
  </ol>
</div>

<script>
function getFormData() {
    var fd = new FormData();
    ['out_trade_no','pid','areaid','account','role_id','money_cent','expand'].forEach(function(name) {
        var el = document.getElementById(name);
        if (el) fd.append(name, el.value);
    });
    return fd;
}
function runStep2() {
    var fd = getFormData(); fd.append('action','step1_notify');
    fetch('?', {method:'POST', body: fd}).then(function(r){ return r.text(); }).then(function(t){ document.open(); document.write(t); document.close(); });
}
function runStep3() {
    var fd = getFormData(); fd.append('action','step3_charge');
    fetch('?', {method:'POST', body: fd}).then(function(r){ return r.text(); }).then(function(t){ document.open(); document.write(t); document.close(); });
}
function runStep4() {
    var fd = getFormData(); fd.append('action','step4_log');
    fetch('?', {method:'POST', body: fd}).then(function(r){ return r.text(); }).then(function(t){ document.open(); document.write(t); document.close(); });
}
function runAll() {
    var fd = getFormData(); fd.append('action','step1_notify');
    fetch('?', {method:'POST', body: fd}).then(function(r){ return r.text(); }).then(function(t){ document.open(); document.write(t); document.close(); });
}
</script>

</body>
</html>
