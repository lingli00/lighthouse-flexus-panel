<?php
/**
 * ============================================================================
 *  腾讯云轻量应用服务器（Lighthouse）运维面板 —— 单文件版
 * ----------------------------------------------------------------------------
 *  环境要求：PHP >= 7.4（推荐 8.0+），开启 curl、session 扩展（宝塔默认已开）
 *  部署方式：把本文件上传到宝塔站点根目录，改名/保持 index.php 直接访问
 *
 *  多云扩展说明（增量开发，未改动原有腾讯云逻辑）：
 *   - 顶部可切换【腾讯轻量云 / 华为云】；后端为适配器模式（TencentAdapter /
 *     HuaweiAdapter），统一输出实例字段 id / name / region / public_ip /
 *     status / cpu / memory / bandwidth，状态文本统一映射（state_cn）。
 *   - 密钥仅保存在前端浏览器 localStorage，随请求传给后端临时使用；后端
 *     不落盘、不写日志、不存数据库、不写会话。配置区常量仅作回退兼容。
 *   - 华为云为自实现 SDK-HMAC-SHA256（V4）签名，见 HwV4Sign 工具类；
 *     华为侧操作为异步任务，复用前端既有轮询逻辑观察状态。
 *
 *  ⚠️ 重要风险提示（写死在代码里，提醒每一次使用的人）
 *  本面板使用【腾讯云主账号 SecretId / SecretKey】调用云 API。主账号密钥 =
 *  账号最高权限，可销毁全部服务器、购买资源、访问全部云产品与账单。
 *  一旦密钥泄露，整个腾讯云账号将完全失控。使用者已明确知悉并自愿承担该风险。
 *  强烈建议：在 CAM 控制台创建仅授权 QcloudLighthouseFullAccess（或只读+指定
 *  操作）的【子账号密钥】后，把 AUTH_MODE 改成 'sub' 并替换密钥。
 *
 *  安全设计：
 *   1. 密钥只存在于 PHP 后端，绝不输出到 HTML / JS / 接口返回值
 *   2. 所有云 API 请求由 PHP 后端代理转发，前端不直接访问腾讯云
 *   3. 面板独立密码 + Session 鉴权 + CSRF Token
 *   4. 高危操作（重装 / 重置密码 / 关机 / 删除安全组规则）红色强提示二次确认
 *   5. 所有操作写入本地日志文件，记录时间、IP、操作类型、实例、结果
 * ============================================================================
 */
declare(strict_types=1);

/* ==========================================================================
 *  一、配置区（只改这里）
 * ========================================================================== */

// 腾讯云 API 密钥（主账号 / 子账号均可，见 AUTH_MODE 说明）
// 配置方式二选一：① 在这里写死（服务端配置，前端无需再填）；② 留空，由前端「密钥配置」面板传入
define('TC_SECRET_ID',  '');
define('TC_SECRET_KEY', ''); // 推荐留空：密钥由前端「密钥配置」传入（localStorage）；此处仅作为服务端直连配置

// 密钥类型：main = 主账号密钥（最高权限，高风险）；sub = 子账号密钥（推荐）
// 仅用于界面风险提示与日志记录，不影响调用逻辑
define('AUTH_MODE', 'main');

// ---------------------------------------------------------------------------
// 华为云（ECS / Flexus）配置 —— 与腾讯云相同，两种方式二选一：
//  ① 服务端配置：在下面写死 AK / SK / ProjectId（写全三项后前端「密钥配置」可不填）
//  ② 前端配置：保持留空，由前端「密钥配置」面板传入（浏览器 localStorage）
// 前端填了密钥时优先使用前端的；前端留空时自动使用这里的服务端配置。
// ---------------------------------------------------------------------------
define('HW_AK',       '');
define('HW_SK',       '');
define('HW_PROJECT',  '');      // IAM 项目 ID（「我的凭证 → 项目列表」）
define('HW_DEFAULT_REGION', 'cn-north-4'); // 华为云默认地域

// 面板登录密码。支持两种写法：
//   明文：define('PANEL_PASSWORD', 'MyPass123');
//   哈希（更安全，推荐）：define('PANEL_PASSWORD', 'sha256:<你的密码的sha256>');
//   生成哈希：echo hash('sha256','你的密码');
define('PANEL_PASSWORD', 'sha256:9d8050bbbc33f1e494147cc0bc07c7c422815670c4e8aced0a97eb73f03f5576'); // 登录密码为 sha256 哈希，明文不落盘

// 默认地域（面板首次加载时选中的地域）
define('DEFAULT_REGION', 'ap-guangzhou');

// 密码复杂度校验规则
//   true  = 按腾讯云官方要求：8~30 位，且至少包含【大写字母/小写字母/数字/特殊符号】中的三类
//   false = 按你自定义的宽松规则：8~16 位，字母 + 数字
define('PASSWORD_STRICT', true);

// 日志文件路径（需保证网站运行用户 www 对该目录有写权限）
define('LOG_FILE', __DIR__ . '/lighthouse_panel.log');

// Session 有效期（秒），超时自动登出
define('SESSION_TTL', 3600);

// 云 API 请求超时（秒）
define('API_TIMEOUT', 25);

// 前端 CDN（如果服务器访问不畅，可自行下载文件放到站点目录并改成相对路径）
define('CDN_TAILWIND', 'https://cdn.tailwindcss.com/3.4.1');
define('CDN_CHARTJS',  'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js');

/* ==========================================================================
 *  二、基础设施（Session / 日志 / 输出）
 * ========================================================================== */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

session_name('lhpanel_sid');
@session_start();

// session 过期
if (!empty($_SESSION['auth']) && !empty($_SESSION['auth_time']) && (time() - (int)$_SESSION['auth_time'] > SESSION_TTL)) {
    $_SESSION = [];
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** UTF-8 安全取长度（mbstring 未安装时自动回退，避免环境差异报错） */
function str_len(string $s): int
{
    if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
    return preg_match_all('/./us', $s, $m) ?: 0;
}

/** UTF-8 安全截断 */
function str_cut(string $s, int $n): string
{
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n);
    preg_match_all('/./us', $s, $m);
    return implode('', array_slice($m[0], 0, $n));
}

function json_out(array $arr): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim(explode(',', (string)$_SERVER[$k])[0]);
            if ($v !== '') return $v;
        }
    }
    return 'unknown';
}

/**
 * 写操作日志（JSON 行格式）
 */
function write_log(string $action, string $target, string $result, string $message = ''): void
{
    $row = [
        'time'    => date('Y-m-d H:i:s'),
        'ip'      => client_ip(),
        'user'    => 'panel(' . substr(session_id(), 0, 8) . ')',
        'auth'    => AUTH_MODE,
        'cloud'   => (string)($GLOBALS['CLOUD'] ?? 'tencent'), // 腾讯 / 华为来源标记（不含任何密钥）
        'action'  => $action,
        'target'  => $target,
        'result'  => $result, // success / fail
        'message' => $message,
    ];
    @file_put_contents(
        LOG_FILE,
        json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * 读取日志（倒序，最近 $limit 条）
 */
function read_logs(int $limit = 200): array
{
    if (!is_file(LOG_FILE)) return [];
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $lines = array_slice($lines, -$limit);
    $out = [];
    foreach (array_reverse($lines) as $ln) {
        $d = json_decode($ln, true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}

class ApiError extends Exception
{
    public string $tcCode = '';
    public string $requestId = '';
    public function __construct(string $message, string $tcCode = '', string $requestId = '')
    {
        parent::__construct($message);
        $this->tcCode   = $tcCode;
        $this->requestId = $requestId;
    }
}

/* ==========================================================================
 *  三、腾讯云 API 3.0 签名（TC3-HMAC-SHA256）与请求封装（零依赖）
 * ========================================================================== */

/* --- 腾讯云运行时密钥：由 dispatch 从请求体注入（前端 localStorage 传入），
 *     仅存在于当前请求内存，不落盘 / 不写会话 / 不入日志；为空时回退配置区常量 --- */
function tc_cred_ak(): string { return (string)($GLOBALS['TC_CRED']['ak'] ?? ''); }
function tc_cred_sk(): string { return (string)($GLOBALS['TC_CRED']['sk'] ?? ''); }

function tc_sign(string $secretId, string $secretKey, string $service, string $action, string $payload, int $timestamp): array
{
    $host             = $service . '.tencentcloudapi.com';
    $algorithm        = 'TC3-HMAC-SHA256';
    $date             = gmdate('Y-m-d', $timestamp);
    $canonicalHeaders = "content-type:application/json\nhost:" . $host . "\nx-tc-action:" . strtolower($action) . "\n";
    $signedHeaders    = 'content-type;host;x-tc-action';
    $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('SHA256', $payload);
    $credentialScope  = $date . '/' . $service . '/tc3_request';
    $stringToSign     = $algorithm . "\n" . $timestamp . "\n" . $credentialScope . "\n" . hash('SHA256', $canonicalRequest);

    $secretDate    = hash_hmac('SHA256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('SHA256', $service, $secretDate, true);
    $secretSigning = hash_hmac('SHA256', 'tc3_request', $secretService, true);
    $signature     = hash_hmac('SHA256', $stringToSign, $secretSigning);

    return [
        'authorization' => $algorithm
            . ' Credential=' . $secretId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature,
        'host'      => $host,
        'timestamp' => (string)$timestamp,
    ];
}

/**
 * 调用腾讯云 API
 * @return array Response 内容
 * @throws ApiError
 */
function tc_call(string $service, string $action, array $params, string $region, string $version = '2020-03-24'): array
{
    if (!function_exists('curl_init')) {
        throw new ApiError('当前 PHP 未启用 curl 扩展，请在宝塔「软件商店 → PHP-设置 → 安装扩展」中安装 curl 后重试');
    }
    // 注意：地域只能通过 X-TC-Region 请求头传递，放入请求体会被判定为未知参数（UnknownParameter）
    // 空参数必须编码为 JSON 对象 {}：json_encode([]) 会产出 "[]"（JSON 数组），
    // 腾讯云会判定「Content-Type=application/json 但内容不是合法 JSON」而报 InvalidParameter
    $payload = json_encode($params ?: new stdClass(), JSON_UNESCAPED_UNICODE);
    $ts      = time();
    // 密钥来源：优先使用 dispatch 注入的前端临时密钥（tc_cred_ak/sk），
    // 为空时回退到配置区常量（服务端配置方式）。密钥仅存在于当前请求内存。
    $tcAk = tc_cred_ak() ?: TC_SECRET_ID;
    $tcSk = tc_cred_sk() ?: TC_SECRET_KEY;
    if ($tcAk === '' || $tcSk === '') {
        throw new ApiError('腾讯云密钥未配置：请在顶部「密钥配置」中填写，或在 index.php 配置区设置 TC_SECRET_ID / TC_SECRET_KEY（两种方式二选一）');
    }
    $sign    = tc_sign($tcAk, $tcSk, $service, $action, $payload, $ts);

    $headers = [
        'Authorization: ' . $sign['authorization'],
        'Content-Type: application/json',
        'Host: ' . $sign['host'],
        'X-TC-Action: ' . $action,
        'X-TC-Version: ' . $version,
        'X-TC-Timestamp: ' . $sign['timestamp'],
        'X-TC-Region: ' . $region,
    ];

    $ch = curl_init('https://' . $sign['host'] . '/');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'lighthouse-panel/1.0',
    ]);
    $body    = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new ApiError('网络请求失败（服务器无法访问腾讯云 API）：' . $curlErr);
    }

    $resp = json_decode($body, true);
    if (!is_array($resp)) {
        throw new ApiError('腾讯云返回内容无法解析（HTTP ' . $httpCode . '）：' . str_cut((string)$body, 200));
    }
    if (!isset($resp['Response'])) {
        throw new ApiError('腾讯云返回格式异常（HTTP ' . $httpCode . '）');
    }
    $r = $resp['Response'];
    if (isset($r['Error'])) {
        $code = (string)($r['Error']['Code'] ?? 'Unknown');
        $msg  = (string)($r['Error']['Message'] ?? '未知错误');
        throw new ApiError(friendly_error($code, $msg), $code, (string)($r['RequestId'] ?? ''));
    }
    return $r;
}

/**
 * 把腾讯云报错翻译成人话
 */
function friendly_error(string $code, string $msg): string
{
    $map = [
        'AuthFailure'                              => '鉴权失败：SecretId / SecretKey 不正确或已禁用',
        'AuthFailure.SignatureExpire'              => '签名过期：服务器本地时间与腾讯云时间偏差过大，请检查服务器时间（NTP）',
        'AuthFailure.SignatureFailure'             => '签名校验失败：SecretKey 填写错误',
        'AuthFailure.InvalidSecretId'              => 'SecretId 不存在或已删除',
        'AuthFailure.MFAFailure'                   => 'MFA 校验失败',
        'UnauthorizedOperation'                    => '无权限：当前密钥没有该操作的授权（子账号请在 CAM 授权）',
        'UnauthorizedOperation.NoPermission'       => '无权限：当前密钥没有该操作的授权（子账号请在 CAM 授权）',
        'UnauthorizedOperation.TokenInvalid'       => '临时密钥 Token 无效',
        'InvalidParameterValue.InstanceIdMalformed' => '实例 ID 格式不正确',
        'InvalidParameterValue.LimitExceeded'      => '参数数量超出上限',
        'InvalidParameterValue.PasswordMalformed'  => '密码格式不符合腾讯云要求（8~30 位，需含大写/小写/数字/特殊符号中至少三类）',
        'InvalidParameterValue.Duplicated'         => '参数重复',
        'InvalidParameterValue.OutOfRange'         => '参数超出允许范围',
        'InvalidParameter.FirewallRulesExist'      => '该防火墙规则已存在',
        'InvalidParameter.FirewallRulesDuplicated' => '防火墙规则重复',
        'LimitExceeded.FirewallRulesLimitExceeded' => '防火墙规则数量已达上限',
        'ResourceNotFound.InstanceIdNotFound'      => '实例不存在（请确认实例 ID 与所在地域是否匹配）',
        'ResourceNotFound.FirewallNotFound'        => '防火墙不存在',
        'ResourceNotFound.BlueprintIdNotFound'     => '镜像不存在或该地域不可用',
        'ResourceUnavailable'                      => '资源不可用',
        'FailedOperation'                          => '操作失败',
        'FailedOperation.FirewallRulesOperationFailed' => '防火墙规则操作失败',
        'UnsupportedOperation.FirewallBusy'        => '防火墙正忙，请稍后重试',
        'UnsupportedOperation.FirewallVersionMismatch' => '防火墙版本不匹配，请刷新规则列表后重试',
        'UnsupportedOperation.InstanceOperationInProgress' => '实例正在执行其他操作，请稍后再试（操作互斥）',
        'UnsupportedOperation.LastOperationUnfinished' => '上次操作尚未完成，请稍后再试',
        'UnsupportedOperation.InstanceStateNotAllowed' => '实例当前状态不允许该操作（例如运行中才能重置密码）',
        'UnsupportedOperation.InvalidInstanceState' => '实例当前状态不支持该操作（开机需先关机、关机/重启需先运行）',
        'InvalidParameterValue.InvalidParameterCombination' => '参数组合不合法（密码需显式设置，不能同时要求自动生成）',
        'InvalidParameterValue.BlueprintId'         => '该镜像不支持重装到当前实例（不允许跨操作系统类型切换）',
        'InvalidParameterValue.NotAllowToChangePlatformType' => '不允许更换操作系统类型（Windows 与 Linux 不能互转）',
        'UnsupportedOperation.OperationNotSupportAutoGeneratePassword' => '重装时必须自定义登录密码，不能由系统自动生成',
        'OperationDenied.InstanceOperationInProgress' => '实例上一次操作尚未完成，请稍后再试',
        'RequestLimitExceeded'                     => 'API 请求频率超限，请稍后再试',
        'InternalError'                            => '腾讯云内部错误，请稍后重试',
    ];
    if (isset($map[$code])) return $map[$code] . '（' . $code . '）';
    foreach ($map as $k => $v) {
        if (strpos($code, $k . '.') === 0) return $v . '（' . $code . '）';
    }
    return $msg . '（' . $code . '）';
}

function lh(array $params, string $region, string $action, string $version = '2020-03-24'): array
{
    return tc_call('lighthouse', $action, $params, $region, $version);
}

/* ==========================================================================
 *  四、数据整理
 * ========================================================================== */

function fmt_bytes($b): string
{
    $b = (float)$b;
    $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($b >= 1024 && $i < 5) { $b /= 1024; $i++; }
    return ($i === 0 ? (int)$b : round($b, 2)) . ' ' . $u[$i];
}

function state_cn(?string $s): string
{
    $m = [
        'PENDING'       => '创建中',
        'LAUNCH_FAILED' => '创建失败',
        'RUNNING'       => '运行中',
        'STOPPED'       => '已关机',
        'STOPPING'      => '关机中',
        'STARTING'      => '开机中',
        'REBOOTING'     => '重启中',
        'RESETTING'     => '重装中',
        'REINSTALLING'  => '重装中',
        'SHUTDOWN'      => '待销毁',
        'TERMINATING'   => '销毁中',
        'ISOLATED'      => '已隔离',
    ];
    $s = (string)$s;
    return $m[$s] ?? ($s === '' ? '未知' : $s);
}

function state_color(?string $s): string
{
    $s = (string)$s;
    if (in_array($s, ['RUNNING'], true)) return 'green';
    if (in_array($s, ['STOPPED', 'SHUTDOWN', 'ISOLATED'], true)) return 'slate';
    if (in_array($s, ['LAUNCH_FAILED', 'TERMINATING'], true)) return 'red';
    return 'amber';
}

/**
 * 实例字段白名单化：只把需要的字段交给前端，避免任何敏感/冗余信息外泄
 */
function normalize_instance(array $i, array $trafficMap, string $region = ''): array
{
    $pub  = [];
    $priv = [];
    foreach ((array)($i['PublicAddresses'] ?? []) as $a)  { $pub[]  = (string)$a; }
    foreach ((array)($i['PrivateAddresses'] ?? []) as $a)  { $priv[] = (string)$a; }

    // IPv6：不同版本返回字段名不一致，做容错
    $v6 = [];
    foreach (['IPv6Addresses', 'Ipv6Addresses', 'PublicIpv6Addresses', 'Ipv6Address'] as $k) {
        if (!empty($i[$k])) {
            if (is_array($i[$k])) { foreach ($i[$k] as $x) { $v6[] = (string)$x; } }
            else { $v6[] = (string)$i[$k]; }
            break;
        }
    }

    $tp = $trafficMap[(string)($i['InstanceId'] ?? '')] ?? null;
    if (!$tp && !empty($i['TrafficPackage'])) {
        $t = $i['TrafficPackage'];
        $tp = [
            'used'      => (float)($t['TrafficUsed'] ?? 0),
            'total'     => (float)($t['TrafficPackageTotal'] ?? 0),
            'remaining' => (float)($t['TrafficPackageRemaining'] ?? 0),
            'overflow'  => (float)($t['TrafficOverflow'] ?? 0),
            'deadline'  => (string)($t['Deadline'] ?? ''),
            'start'     => (string)($t['StartTime'] ?? ''),
            'end'       => (string)($t['EndTime'] ?? ''),
            'status'    => (string)($t['Status'] ?? ''),
        ];
    }

    return [
        // 统一输出字段（适配器约定）：id / name / region / public_ip / status /
        // cpu / memory / bandwidth —— 其余为前端渲染所需的扩展字段
        'id'        => (string)($i['InstanceId'] ?? ''),
        'name'      => (string)($i['InstanceName'] ?? ''),
        'region'    => $region,
        'public_ip' => $pub[0] ?? '',
        'status'    => (string)($i['InstanceState'] ?? ''),
        'zone'      => (string)($i['Zone'] ?? ''),
        'os'        => (string)($i['OsName'] ?? ''),
        'platform'  => (string)($i['PlatformType'] ?? $i['Platform'] ?? ''),
        'blueprint' => (string)($i['BlueprintId'] ?? ''),
        'bundle'    => (string)($i['BundleId'] ?? ''),
        'cpu'       => (int)($i['CPU'] ?? 0),
        'mem'       => (int)($i['Memory'] ?? 0),
        'disk'      => (int)($i['SystemDisk']['DiskSize'] ?? 0),
        'bandwidth' => (int)($i['InternetAccessible']['InternetMaxBandwidthOut'] ?? 0),
        'charge'    => (string)($i['InstanceChargeType'] ?? (!empty($i['InstanceChargePrepaid']) ? 'PREPAID' : '')),
        'expired'   => (string)($i['ExpiredTime'] ?? ''),
        'created'   => (string)($i['CreatedTime'] ?? ''),
        'state'     => (string)($i['InstanceState'] ?? ''),
        'stateCn'   => state_cn($i['InstanceState'] ?? ''),
        'stateColor'=> state_color($i['InstanceState'] ?? ''),
        'public'    => $pub,
        'private'   => $priv,
        'ipv6'      => $v6,
        'traffic'   => $tp,
    ];
}

/* ==========================================================================
 *  五、业务接口（全部走后端代理，密钥不出 PHP）
 * ========================================================================== */

function biz_bootstrap(): array
{
    $regions = [];
    try {
        $r = lh([], DEFAULT_REGION, 'DescribeRegions');
        foreach ((array)($r['RegionSet'] ?? []) as $x) {
            $regions[] = [
                'region' => (string)($x['Region'] ?? ''),
                'name'   => (string)($x['RegionName'] ?? $x['Region'] ?? ''),
            ];
        }
    } catch (ApiError $e) {
        $regions = fallback_regions();
    }
    if (!$regions) $regions = fallback_regions();

    // 密码规则
    if (PASSWORD_STRICT) {
        $rule = [
            'min'  => 8,
            'max'  => 30,
            'desc' => '8~30 位，需包含大写字母、小写字母、数字、特殊符号中的至少三类（腾讯云官方要求）',
        ];
    } else {
        $rule = [
            'min'  => 8,
            'max'  => 16,
            'desc' => '8~16 位，需同时包含字母和数字',
        ];
    }

    $rule['strict'] = PASSWORD_STRICT ? 1 : 0;
    return [
        'cloud'      => 'tencent',
        'regions'    => $regions,
        'region'     => DEFAULT_REGION,
        'authMode'   => AUTH_MODE,
        'pwdRule'    => $rule,
        'logFile'    => LOG_FILE,
        'logWritable'=> is_writable(dirname(LOG_FILE)) || (is_file(LOG_FILE) && is_writable(LOG_FILE)),
    ];
}

function fallback_regions(): array
{
    $r = [
        'ap-guangzhou' => '华南地区(广州)',
        'ap-shanghai'  => '华东地区(上海)',
        'ap-beijing'   => '华北地区(北京)',
        'ap-chengdu'   => '西南地区(成都)',
        'ap-nanjing'   => '华东地区(南京)',
        'ap-hongkong'  => '港澳台地区(中国香港)',
        'ap-singapore' => '亚太地区(新加坡)',
        'ap-tokyo'     => '亚太地区(东京)',
        'ap-seoul'     => '亚太地区(首尔)',
        'ap-bangkok'   => '亚太地区(曼谷)',
        'ap-jakarta'   => '亚太地区(雅加达)',
        'ap-mumbai'    => '亚太地区(孟买)',
        'ap-siliconvalley' => '美国西部(硅谷)',
        'na-ashburn'   => '美国东部(弗吉尼亚)',
        'eu-frankfurt' => '欧洲地区(法兰克福)',
    ];
    $out = [];
    foreach ($r as $k => $v) $out[] = ['region' => $k, 'name' => $v];
    return $out;
}

function biz_instances(string $region): array
{
    $list = [];
    $offset = 0;
    do {
        $r = lh(['Offset' => $offset, 'Limit' => 100], $region, 'DescribeInstances');
        foreach ((array)($r['InstanceSet'] ?? []) as $i) $list[] = $i;
        $total = (int)($r['TotalCount'] ?? count($list));
        $offset += 100;
    } while ($offset < $total);

    // 流量包明细（失败不影响主流程）
    $trafficMap = [];
    if ($list) {
        $ids = [];
        foreach ($list as $i) $ids[] = (string)($i['InstanceId'] ?? '');
        try {
            $t = lh(['InstanceIds' => $ids, 'Limit' => 100], $region, 'DescribeInstancesTrafficPackages');
            foreach ((array)($t['InstanceTrafficPackageSet'] ?? []) as $it) {
                $set = (array)($it['TrafficPackageSet'] ?? []);
                $first = $set[0] ?? [];
                $trafficMap[(string)($it['InstanceId'] ?? '')] = [
                    'used'      => (float)($first['TrafficUsed'] ?? 0),
                    'total'     => (float)($first['TrafficPackageTotal'] ?? 0),
                    'remaining' => (float)($first['TrafficPackageRemaining'] ?? 0),
                    'overflow'  => (float)($first['TrafficOverflow'] ?? 0),
                    'deadline'  => (string)($first['Deadline'] ?? ''),
                    'start'     => (string)($first['StartTime'] ?? ''),
                    'end'       => (string)($first['EndTime'] ?? ''),
                    'status'    => (string)($first['Status'] ?? ''),
                ];
            }
        } catch (ApiError $e) {
            // 忽略：流量包接口失败时回退到实例自带字段
        }
    }

    $out = [];
    foreach ($list as $i) $out[] = normalize_instance($i, $trafficMap, $region);
    return ['instances' => $out, 'region' => $region];
}

function biz_blueprints(string $region, string $instanceId = ''): array
{
    $out = [];
    if ($instanceId !== '') {
        // 按实例查询「可重装」镜像：自动排除跨系统类型、配置不满足等不可用镜像
        $offset = 0;
        do {
            $r   = lh(['InstanceId' => $instanceId, 'Offset' => $offset, 'Limit' => 20], $region, 'DescribeResetInstanceBlueprints');
            $set = (array)($r['ResetInstanceBlueprintSet'] ?? []);
            foreach ($set as $x) {
                $b    = (array)($x['BlueprintInfo'] ?? $x);
                $type = (string)($b['BlueprintType'] ?? '');
                $out[] = [
                    'id'         => (string)($b['BlueprintId'] ?? ''),
                    'title'      => (string)($b['DisplayTitle'] ?? $b['BlueprintName'] ?? ''),
                    'version'    => (string)($b['DisplayVersion'] ?? ''),
                    'desc'       => (string)($b['Description'] ?? ''),
                    'os'         => (string)($b['OsName'] ?? ''),
                    'platform'   => (string)($b['PlatformType'] ?? ''),
                    'type'       => $type,
                    'typeCn'     => ['APP_OS' => '应用镜像', 'PURE_OS' => '纯净镜像', 'DOCKER' => 'Docker 镜像', 'PRIVATE' => '自定义镜像'][$type] ?? $type,
                    'resettable' => (bool)($x['IsResettable'] ?? true),
                    'note'       => (string)($x['NonResettableMessage'] ?? ''),
                ];
            }
            $total   = (int)($r['TotalCount'] ?? count($out));
            $offset += 20;
        } while ($set && $offset < $total);
        $out = array_values(array_filter($out, fn($b) => $b['resettable'] === true));
    } else {
        $r = lh(['Limit' => 100], $region, 'DescribeBlueprints');
        foreach ((array)($r['BlueprintSet'] ?? []) as $b) {
            $type = (string)($b['BlueprintType'] ?? '');
            $out[] = [
                'id'       => (string)($b['BlueprintId'] ?? ''),
                'title'    => (string)($b['DisplayTitle'] ?? $b['BlueprintName'] ?? ''),
                'version'  => (string)($b['DisplayVersion'] ?? ''),
                'desc'     => (string)($b['Description'] ?? ''),
                'os'       => (string)($b['OsName'] ?? ''),
                'platform' => (string)($b['PlatformType'] ?? ''),
                'type'     => $type,
                'typeCn'   => ['APP_OS' => '应用镜像', 'PURE_OS' => '纯净镜像', 'DOCKER' => 'Docker 镜像', 'PRIVATE' => '自定义镜像'][$type] ?? $type,
                'resettable' => true,
                'note'     => '',
            ];
        }
    }
    // 分组排序：纯净镜像在前
    usort($out, function ($a, $b) {
        $o = ['PURE_OS' => 0, 'APP_OS' => 1, 'DOCKER' => 2, 'PRIVATE' => 3];
        $oa = $o[$a['type']] ?? 9; $ob = $o[$b['type']] ?? 9;
        if ($oa === $ob) return strcmp($a['title'], $b['title']);
        return $oa <=> $ob;
    });
    return ['blueprints' => $out];
}

function biz_power(array $d): array
{
    $region = (string)$d['region'];
    $ids    = [(string)$d['instanceId']];
    $op     = (string)$d['op'];
    switch ($op) {
        case 'start':  $action = 'StartInstances';  $params = []; break;
        case 'stop':   $action = 'StopInstances';   $params = []; break; // 轻量实例不支持 StoppedMode 参数
        case 'reboot': $action = 'RebootInstances'; $params = []; break;
        default: throw new ApiError('未知的操作类型');
    }
    $p = array_merge(['InstanceIds' => $ids], $params);
    $r = lh($p, $region, $action);
    $cn = ['start' => '开机', 'stop' => '关机', 'reboot' => '重启'][$op];
    write_log($action, $region . '/' . $ids[0], 'success', $cn . '指令已下发');
    return ['msg' => $cn . '指令已下发，实例状态稍后自动刷新'];
}

function biz_reset_password(array $d): array
{
    $region = (string)$d['region'];
    $ids    = [(string)$d['instanceId']];
    $pwd    = (string)$d['password'];
    if (!check_password($pwd)) throw new ApiError('密码不符合复杂度要求：' . password_desc());
    $p = ['InstanceIds' => $ids, 'Password' => $pwd];
    if (!empty($d['username'])) $p['UserName'] = (string)$d['username'];
    lh($p, $region, 'ResetInstancesPassword');
    write_log('ResetInstancesPassword', $region . '/' . $ids[0], 'success', '重置实例管理员密码');
    return ['msg' => '重置密码指令已下发，一般几分钟内生效；若实例处于运行中，部分系统需重启后生效'];
}

function biz_reset_system(array $d): array
{
    $region  = (string)$d['region'];
    $id      = (string)$d['instanceId'];
    $bp      = (string)$d['blueprintId'];
    if ($bp === '') throw new ApiError('请选择要安装的镜像');
    $p = ['InstanceId' => $id, 'BlueprintId' => $bp];
    if (!empty($d['password'])) {
        if (!check_password((string)$d['password'])) throw new ApiError('新密码不符合复杂度要求：' . password_desc());
        // AutoGeneratePassword 只接受 YES/NO；ResetInstance 不支持 YES，
        // 只传 Password 会与默认值冲突，必须显式传 'NO'
        $p['LoginConfiguration'] = ['AutoGeneratePassword' => 'NO', 'Password' => (string)$d['password']];
    }
    lh($p, $region, 'ResetInstance');
    write_log('ResetInstance', $region . '/' . $id, 'success', '重装系统，镜像 ' . $bp);
    return ['msg' => '重装任务已下发，通常 3~10 分钟完成；页面将自动轮询实例状态'];
}

function biz_rename(array $d): array
{
    $region = (string)$d['region'];
    $id     = (string)$d['instanceId'];
    $name   = trim((string)$d['name']);
    if ($name === '') throw new ApiError('实例名称不能为空');
    if (str_len($name) > 60) throw new ApiError('实例名称过长（最多 60 个字符）');
    lh(['InstanceIds' => [$id], 'InstanceName' => $name], $region, 'ModifyInstancesAttribute');
    write_log('ModifyInstancesAttribute', $region . '/' . $id, 'success', '重命名为 ' . $name);
    return ['msg' => '实例名称已更新'];
}

function biz_snapshot_list(array $d): array
{
    $region = (string)($d['region'] ?? DEFAULT_REGION);
    $iid    = (string)($d['instanceId'] ?? '');
    $params = ['Limit' => 100, 'Offset' => 0];
    if ($iid !== '') $params['Filters'] = [['Name' => 'instance-id', 'Values' => [$iid]]];

    $list   = [];
    $offset = 0;
    do {
        $params['Offset'] = $offset;
        $r    = lh($params, $region, 'DescribeSnapshots');
        $set  = (array)($r['SnapshotSet'] ?? []);
        foreach ($set as $x) $list[] = normalize_snapshot($x);
        $total  = (int)($r['TotalCount'] ?? count($list));
        $offset += 100;
    } while ($set && $offset < $total);

    // 快照接口不直接回实例 ID：用 DiskId 反查所属实例
    if ($iid === '' && $list) {
        $map = snapshot_disk_owner($region);
        foreach ($list as &$sn) {
            $sn['instanceId'] = $map[$sn['diskId']] ?? '';
        }
        unset($sn);
    } elseif ($iid !== '') {
        foreach ($list as &$sn) $sn['instanceId'] = $iid;
        unset($sn);
    }

    usort($list, fn($a, $b) => strcmp($b['created'], $a['created']));
    return ['snapshots' => $list, 'region' => $region];
}

/** 系统盘 DiskId -> 实例 ID 映射 */
function snapshot_disk_owner(string $region): array
{
    $map = [];
    try {
        $offset = 0;
        do {
            $r   = lh(['Offset' => $offset, 'Limit' => 100], $region, 'DescribeInstances');
            $set = (array)($r['InstanceSet'] ?? []);
            foreach ($set as $i) {
                $diskId = (string)($i['SystemDisk']['DiskId'] ?? '');
                if ($diskId !== '') $map[$diskId] = (string)($i['InstanceId'] ?? '');
            }
            $total   = (int)($r['TotalCount'] ?? count($set));
            $offset += 100;
        } while ($set && $offset < $total);
    } catch (ApiError $e) {
        // 反查失败不影响列表展示
    }
    return $map;
}

function normalize_snapshot(array $x): array
{
    $state = (string)($x['SnapshotState'] ?? '');
    return [
        'id'         => (string)($x['SnapshotId'] ?? ''),
        'name'       => (string)($x['SnapshotName'] ?? ''),
        'diskId'     => (string)($x['DiskId'] ?? ''),
        'diskUsage'  => (string)($x['DiskUsage'] ?? ''),
        'diskUsageCn'=> ['SYSTEM_DISK' => '系统盘', 'DATA_DISK' => '数据盘'][(string)($x['DiskUsage'] ?? '')] ?? (string)($x['DiskUsage'] ?? ''),
        'size'       => (int)($x['DiskSize'] ?? 0),
        'state'      => $state,
        'stateCn'    => ['NORMAL' => '可用', 'CREATING' => '创建中', 'ROLLBACKING' => '回滚中', 'DELETING' => '删除中'][$state] ?? $state,
        'percent'    => (int)($x['Percent'] ?? 0),
        'created'    => (string)($x['CreatedTime'] ?? ''),
        'instanceId' => '',
    ];
}

function biz_snapshot_create(array $d): array
{
    $region = (string)$d['region'];
    $iid    = (string)$d['instanceId'];
    $name   = trim((string)($d['name'] ?? ''));
    if ($name === '') $name = 'snap-' . date('Ymd-His');
    if (!preg_match('/^[a-zA-Z0-9._\-\x{4e00}-\x{9fa5}]{1,64}$/u', $name)) {
        throw new ApiError('快照名称只能包含中文、字母、数字、点、下划线、短横线，且不超过 64 个字符');
    }
    lh(['InstanceId' => $iid, 'SnapshotName' => $name], $region, 'CreateInstanceSnapshot');
    write_log('CreateInstanceSnapshot', $region . '/' . $iid, 'success', '创建快照 ' . $name);
    return ['msg' => '快照创建任务已下发，通常需要数分钟（耗时与磁盘大小有关）'];
}

function biz_snapshot_delete(array $d): array
{
    $region = (string)$d['region'];
    $ids    = array_values(array_filter((array)($d['snapshotIds'] ?? []), fn($x) => is_string($x) && $x !== ''));
    if (!$ids) throw new ApiError('请先选择要删除的快照');
    lh(['SnapshotIds' => $ids], $region, 'DeleteSnapshots');
    write_log('DeleteSnapshots', $region . '/' . implode(',', $ids), 'success', '删除快照 ' . count($ids) . ' 个');
    return ['msg' => '已删除 ' . count($ids) . ' 个快照'];
}

function biz_snapshot_apply(array $d): array
{
    $region = (string)$d['region'];
    $iid    = (string)$d['instanceId'];
    $sid    = (string)$d['snapshotId'];
    if ($sid === '') throw new ApiError('请选择要回滚的快照');
    lh(['InstanceId' => $iid, 'SnapshotId' => $sid], $region, 'ApplyInstanceSnapshot');
    write_log('ApplyInstanceSnapshot', $region . '/' . $iid, 'success', '回滚到快照 ' . $sid);
    return ['msg' => '回滚任务已下发。回滚会覆盖系统盘当前数据，实例将短暂重启'];
}

function biz_fw_list(array $d): array
{
    $region = (string)$d['region'];
    $id     = (string)$d['instanceId'];
    $r = lh(['InstanceId' => $id, 'Offset' => 0, 'Limit' => 100], $region, 'DescribeFirewallRules');
    $rules = [];
    foreach ((array)($r['FirewallRuleSet'] ?? []) as $x) {
        $rules[] = [
            'protocol' => (string)($x['Protocol'] ?? ''),
            'port'     => (string)($x['Port'] ?? ''),
            'cidr'     => (string)($x['CidrBlock'] ?? ''),
            'action'   => (string)($x['Action'] ?? ''),
            'desc'     => (string)($x['FirewallRuleDescription'] ?? ''),
        ];
    }
    return ['rules' => $rules, 'version' => (int)($r['FirewallVersion'] ?? 0)];
}

function biz_fw_add(array $d): array
{
    $region = (string)$d['region'];
    $id     = (string)$d['instanceId'];
    $rule   = build_rule($d['rule'] ?? []);
    lh(['InstanceId' => $id, 'FirewallRules' => [$rule['api']]], $region, 'CreateFirewallRules');
    write_log('CreateFirewallRules', $region . '/' . $id, 'success',
        '新增规则 ' . $rule['api']['Protocol'] . '/' . $rule['api']['Port'] . ' from ' . $rule['api']['CidrBlock'] . ' ' . $rule['api']['Action']);
    return ['msg' => '防火墙规则已添加'];
}

function biz_fw_del(array $d): array
{
    $region = (string)$d['region'];
    $id     = (string)$d['instanceId'];
    $rule   = build_rule($d['rule'] ?? []);
    lh(['InstanceId' => $id, 'FirewallRules' => [$rule['api']]], $region, 'DeleteFirewallRules');
    write_log('DeleteFirewallRules', $region . '/' . $id, 'success',
        '删除规则 ' . $rule['api']['Protocol'] . '/' . $rule['api']['Port'] . ' from ' . $rule['api']['CidrBlock']);
    return ['msg' => '防火墙规则已删除'];
}

function build_rule(array $r): array
{
    $protocol = strtoupper(trim((string)($r['protocol'] ?? 'TCP')));
    $port     = trim((string)($r['port'] ?? ''));
    $cidr     = trim((string)($r['cidr'] ?? '0.0.0.0/0'));
    $action   = strtoupper(trim((string)($r['action'] ?? 'ACCEPT')));
    $desc     = trim((string)($r['desc'] ?? ''));

    if (!in_array($protocol, ['TCP', 'UDP', 'ICMP', 'ICMPV6', 'ALL'], true)) throw new ApiError('协议取值只能为 TCP / UDP / ICMP / ICMPv6 / ALL');
    if (!in_array($action, ['ACCEPT', 'DROP'], true)) throw new ApiError('策略取值只能为 ACCEPT（放行）或 DROP（拒绝）');
    if ($cidr === '') $cidr = '0.0.0.0/0';
    if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/', $cidr, $mm)) {
        throw new ApiError('源地址格式不正确，示例：0.0.0.0/0 或 1.2.3.4/32');
    }
    for ($k = 1; $k <= 4; $k++) {
        if ((int)$mm[$k] > 255) throw new ApiError('源地址格式不正确：IP 每段不能超过 255');
    }
    if (isset($mm[5]) && (int)$mm[5] > 32) throw new ApiError('源地址格式不正确：掩码需在 0~32 之间');
    if (in_array($protocol, ['TCP', 'UDP'], true)) {
        if ($port === '') throw new ApiError('TCP/UDP 规则必须填写端口（单个端口、逗号分隔、或 8000-8080 范围，或 ALL）');
        if ($port !== 'ALL' && !preg_match('/^\d{1,5}(-\d{1,5})?(,\d{1,5}(-\d{1,5})?)*$/', $port)) {
            throw new ApiError('端口格式不正确，示例：80、80,443、8000-8080、ALL');
        }
        if (strlen($port) > 64) throw new ApiError('端口字段过长（最多 64 字符）');
    } else {
        $port = ($port === '' || strtoupper($port) === 'ALL') ? 'ALL' : $port;
    }
    if (str_len($desc) > 64) $desc = str_cut($desc, 64);

    $api = [
        'Protocol'                => $protocol,
        'Port'                    => $port,
        'CidrBlock'               => $cidr,
        'Action'                  => $action,
        'FirewallRuleDescription' => $desc,
    ];
    return ['api' => $api];
}

/**
 * 监控数据：自动尝试合适的统计粒度
 */
function biz_monitor(array $d): array
{
    $region = (string)$d['region'];
    $id     = (string)$d['instanceId'];
    $range  = (string)($d['range'] ?? '24h');

    $hours = ($range === '7d') ? 168 : 24;
    $end   = time();
    $start = $end - $hours * 3600;
    $periods = ($range === '7d') ? [900, 3600, 300] : [300, 60];

    $metrics = [
        ['key' => 'cpu',  'metric' => 'CpuUsage',            'unit' => '%',     'name' => 'CPU 使用率',    'scale' => 1],
        ['key' => 'mem',  'metric' => 'MemUsage',            'unit' => '%',     'name' => '内存使用率',    'scale' => 1],
        ['key' => 'bwout','metric' => 'LighthouseOuttraffic','unit' => 'Mbps',  'name' => '公网出带宽',    'scale' => 8],
        ['key' => 'bwin', 'metric' => 'LighthouseIntraffic', 'unit' => 'Mbps',  'name' => '公网入带宽',    'scale' => 8],
    ];

    $out = [];
    foreach ($metrics as $m) {
        $data = ['points' => [], 'unit' => $m['unit'], 'name' => $m['name']];
        foreach ($periods as $period) {
            try {
                $r = tc_call('monitor', 'GetMonitorData', [
                    'Namespace'  => 'QCE/LIGHTHOUSE',
                    'MetricName' => $m['metric'],
                    'Period'     => $period,
                    'StartTime'  => date('Y-m-d\TH:i:s+08:00', $start),
                    'EndTime'    => date('Y-m-d\TH:i:s+08:00', $end),
                    'Instances'  => [['Dimensions' => [['Name' => 'InstanceId', 'Value' => $id]]]],
                ], $region, '2018-07-24');
            } catch (ApiError $e) {
                continue;
            }
            $dp = $r['DataPoints'][0] ?? [];
            $ts = (array)($dp['Timestamps'] ?? []);
            $vs = (array)($dp['Values'] ?? []);
            if (count($ts) > 0) {
                $data['points'] = sample_points($ts, $vs, $m['scale'], 500);
                $data['period'] = $period;
                break;
            }
        }
        $out[$m['key']] = $data;
    }
    $empty = true;
    foreach ($out as $v) { if (!empty($v['points'])) $empty = false; }
    return [
        'series' => $out,
        'range'  => $range,
        'empty'  => $empty,
        'note'   => $empty ? '未拉取到监控数据：请确认实例已安装腾讯云监控组件（轻量默认内置），或该时间段内无数据上报' : '',
    ];
}

/**
 * 抽稀 + 单位换算，返回 [[tsMs, value], ...]
 */
function sample_points(array $ts, array $vs, float $scale, int $max): array
{
    $n = count($ts);
    if ($n === 0) return [];
    $step = (int)ceil($n / $max);
    $out = [];
    for ($i = 0; $i < $n; $i += $step) {
        $sum = 0; $cnt = 0;
        for ($j = $i; $j < min($i + $step, $n); $j++) {
            $v = $vs[$j] ?? null;
            if ($v === null) continue;
            $sum += (float)$v; $cnt++;
        }
        if ($cnt === 0) continue;
        $out[] = [((int)$ts[$i]) * 1000, round($sum / $cnt * $scale, 3)];
    }
    return $out;
}

function password_desc(): string
{
    return PASSWORD_STRICT
        ? '8~30 位，需包含大写字母、小写字母、数字、特殊符号中的至少三类'
        : '8~16 位，需同时包含字母和数字';
}

function check_password(string $pwd): bool
{
    $len = strlen($pwd);
    if (PASSWORD_STRICT) {
        if ($len < 8 || $len > 30) return false;
        if (preg_match('/\s/', $pwd)) return false;
        $kinds = 0;
        if (preg_match('/[A-Z]/', $pwd)) $kinds++;
        if (preg_match('/[a-z]/', $pwd)) $kinds++;
        if (preg_match('/[0-9]/', $pwd)) $kinds++;
        if (preg_match('/[^A-Za-z0-9]/', $pwd)) $kinds++;
        return $kinds >= 3;
    }
    if ($len < 8 || $len > 16) return false;
    return (bool)(preg_match('/[A-Za-z]/', $pwd) && preg_match('/[0-9]/', $pwd));
}

/* ==========================================================================
 *  六、华为云 V4 签名工具类（SDK-HMAC-SHA256，零依赖）
 * ---------------------------------------------------------------------------
 *  华为云 API 网关签名规范（与 AWS SigV4 不同：签名密钥不做逐级派生，单次 HMAC）：
 *    CanonicalRequest =
 *        HTTPRequestMethod + '\n' + CanonicalURI + '\n' + CanonicalQueryString
 *      + '\n' + CanonicalHeaders + '\n' + SignedHeaders + '\n'
 *      + HexEncode(Hash(RequestPayload))
 *    StringToSign = 'SDK-HMAC-SHA256' + '\n' + X-Sdk-Date + '\n'
 *                 + HexEncode(Hash(CanonicalRequest))
 *    Signature     = HexEncode(HMAC-SHA256(StringToSign, SK))
 *    Authorization = 'SDK-HMAC-SHA256 Access={AK}, SignedHeaders=..., Signature=...'
 *    CanonicalURI（签名用）= 每段编码后末尾补「/」，如 /v1/{proj}/servers/detail/
 *  参与签名的头固定为 host 与 x-sdk-date（key 全小写、按字典序、值 trim）。
 * ========================================================================== */
final class HwV4Sign
{
    /**
     * 构造签名请求头
     * @return array{Host:string, X-Sdk-Date:string, Authorization:string}
     */
    public static function signHeaders(
        string $ak,
        string $sk,
        string $method,
        string $host,
        string $path,
        array $query = [],
        string $payload = ''
    ): array {
        $ts = gmdate('Ymd\THis\Z'); // X-Sdk-Date：ISO8601 基本格式（UTC）

        $hdrs = [
            'host'       => $host,
            'x-sdk-date' => $ts,
        ];
        ksort($hdrs);
        $canonicalHeaders = '';
        foreach ($hdrs as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        }
        $signedHeaders = implode(';', array_keys($hdrs));

        $canonicalRequest = strtoupper($method) . "\n"
            . self::canonicalUri($path) . "\n"
            . self::canonicalQuery($query) . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . hash('sha256', $payload);

        $stringToSign = 'SDK-HMAC-SHA256' . "\n" . $ts . "\n" . hash('sha256', $canonicalRequest);
        $signature    = hash_hmac('sha256', $stringToSign, $sk); // 注意：单次 HMAC，不做密钥派生

        return [
            'Host'          => $host,
            'X-Sdk-Date'    => $ts,
            // 官方格式（见华为云《API签名指南》与官方 SDK AKSKSigner.java）：
            //   SDK-HMAC-SHA256 Access={AK}, SignedHeaders={...}, Signature={sig}
            // 注意：不是 AWS 风格的 Credential={AK}/{时间戳}，Access 后不接时间戳
            'Authorization' => 'SDK-HMAC-SHA256'
                . ' Access=' . $ak
                . ', SignedHeaders=' . $signedHeaders
                . ', Signature=' . $signature,
        ];
    }

    /** CanonicalURI（签名用）：按段编码后每段末尾补「/」。
     *  官方规范：计算签名时 URI 必须以「/」结尾（与官方 SDK AKSKSigner.buildCanonicalUri 一致）。
     *  ⚠ 经真实密钥实测：网关按「每段补 /」的形式重建签名原文，缺尾斜杠必报
     *  APIGW.0301 verify ak sk signature failed；实际请求 URL 则用 requestUri()（不带尾斜杠）。 */
    public static function canonicalUri(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/') return '/';
        $out = '';
        foreach (explode('/', $path) as $seg) {
            $out .= self::enc($seg) . '/';
        }
        return $out;
    }

    /** 请求 URL 用的路径：按段 RFC3986 编码，不补尾斜杠 */
    public static function requestUri(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $out = '';
        foreach (explode('/', $path) as $seg) {
            if ($seg === '') continue;
            $out .= '/' . self::enc($seg);
        }
        return $out === '' ? '/' : $out;
    }

    /** CanonicalQueryString：按 key、value 字典序排序后编码，& 连接 */
    public static function canonicalQuery(array $q): string
    {
        if (!$q) return '';
        $pairs = [];
        foreach ($q as $k => $v) {
            $pairs[] = [self::enc((string)$k), self::enc((string)$v)];
        }
        usort($pairs, fn($a, $b) => $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]));
        return implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $pairs));
    }

    /** RFC3986 严格编码（~ 不编码，空格为 %20） */
    public static function enc(string $s): string
    {
        return str_replace('%7E', '~', rawurlencode($s));
    }
}

/* ==========================================================================
 *  七、华为云 API 请求封装（curl + HwV4Sign，零依赖）
 * ---------------------------------------------------------------------------
 *  华为云运行时密钥由 dispatch 从请求体注入（$GLOBALS['HW_CRED']，前端
 *  localStorage 传入），仅存在于当前请求内存；为空时回退配置区常量。
 * ========================================================================== */

function hw_cred(): array
{
    $c  = (array)($GLOBALS['HW_CRED'] ?? []);
    $ak = trim((string)($c['ak'] ?? HW_AK));
    $sk = trim((string)($c['sk'] ?? HW_SK));
    $pr = trim((string)($c['project'] ?? HW_PROJECT));
    if ($ak === '' || $sk === '') {
        throw new ApiError('华为云密钥未配置：请在顶部「密钥配置」中填写 AK / SK，或在 index.php 配置区设置 HW_AK / HW_SK（两种方式二选一）');
    }
    if ($pr === '') {
        throw new ApiError('华为云 ProjectId 未配置：请在「密钥配置」中填写（IAM 项目 ID），或在 index.php 配置区设置 HW_PROJECT（两种方式二选一）');
    }
    return ['ak' => $ak, 'sk' => $sk, 'project' => $pr];
}

/**
 * 调用华为云 OpenAPI
 * @param string $region  地域，如 cn-north-4；传空字符串 = 全局端点（如 iam / bss，域名不带地域前缀）
 * @param string $service 端点前缀：ecs / evs / ces / vpc / ims / iam / bss 等
 * @param string $method  GET / POST / PUT / DELETE
 * @param string $path    路径，可含 {project_id} 占位符
 * @param string ...$projectOverride 临时替换 {project_id}（用于跨项目查询，如 ProjectId 归属地反查）
 * @return array 解析后的 JSON 响应
 * @throws ApiError
 */
function hw_call(string $region, string $service, string $method, string $path, array $query = [], ?array $json = null, ?string $projectOverride = null): array
{
    if (!function_exists('curl_init')) {
        throw new ApiError('当前 PHP 未启用 curl 扩展，请在宝塔「软件商店 → PHP-设置 → 安装扩展」中安装 curl 后重试');
    }
    $cred = hw_cred();
    $project = ($projectOverride !== null && $projectOverride !== '') ? $projectOverride : $cred['project'];
    $path = str_replace('{project_id}', $project, $path);
    $host = $region === '' ? ($service . '.myhuaweicloud.com') : ($service . '.' . $region . '.myhuaweicloud.com');
    $body = $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE);
    $sign = HwV4Sign::signHeaders($cred['ak'], $cred['sk'], $method, $host, $path, $query, $body);

    // 请求 URL 用正常路径（requestUri）；签名用带尾斜杠的 canonicalUri —— 两者不同，勿混用
    $url = 'https://' . $host . HwV4Sign::requestUri($path);
    if ($query) $url .= '?' . HwV4Sign::canonicalQuery($query);

    $headers = [
        'Authorization: ' . $sign['Authorization'],
        'X-Sdk-Date: '    . $sign['X-Sdk-Date'],
        'Host: '          . $sign['Host'],
    ];
    if ($body !== '') $headers[] = 'Content-Type: application/json';
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'hw-ecs-panel/1.0',
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new ApiError('网络请求失败（服务器无法访问华为云 API）：' . $err);
    }
    $data = json_decode((string)$resp, true);
    if ($http >= 300) {
        $code = '';
        $msg  = '';
        if (is_array($data)) {
            if (isset($data['error']['code'])) {           // IAM / VPC 风格
                $code = (string)$data['error']['code'];
                $msg  = (string)($data['error']['message'] ?? '');
            } elseif (isset($data['error_code'])) {        // 通用风格
                $code = (string)$data['error_code'];
                $msg  = (string)($data['error_msg'] ?? $data['message'] ?? '');
            } elseif (isset($data['itemcode'])) {          // ECS 风格
                $code = (string)$data['itemcode'];
                $msg  = (string)($data['message'] ?? '');
            }
        }
        throw new ApiError(hw_friendly_error($code, $msg, $http), $code);
    }
    return is_array($data) ? $data : [];
}

/** 把华为云报错翻译成人话 */
function hw_friendly_error(string $code, string $msg, int $http): string
{
    $map = [
        'ECS.0001' => '实例正在执行其他操作，请稍后再试（操作互斥）',
        'ECS.0002' => '资源不足或配额超限',
        'ECS.0003' => '实例被锁定，请稍后再试',
        'ECS.0005' => '任务过于频繁，请稍后再试',
        'EVS.1004' => '快照配额不足',
        'EVS.2002' => '快照正在回滚中，不能重复操作',
    ];
    if ($http === 401 || $http === 403 || strcasecmp($code, 'IAM.0003') === 0
        || stripos($msg, 'token') !== false || stripos($msg, 'signature') !== false) {
        // 网关返回 "verify ak sk signature failed" 时，网关回显的 canonical_request 与面板构造一致，
        // 说明请求格式正确、纯粹是 AK 对应的 SK 校验不过：常见于 AK/SK 不是同一对、密钥已重建、复制不完整
        if (stripos($msg, 'verify ak sk signature') !== false) {
            return '鉴权失败：签名校验不通过——通常是 SK 与 AK 不是同一对（密钥已重建 / 复制不完整）。'
                . '请删除旧访问密钥，重新生成一对，AK 与 SK 一起从同一个 credentials.csv 复制（'
                . ($code ?: 'HTTP ' . $http) . '：' . $msg . '）';
        }
        return '鉴权失败：请检查 AK / SK / ProjectId 是否正确，ProjectId 需为所选地域对应的项目（'
            . ($code ?: 'HTTP ' . $http) . ($msg !== '' ? '：' . $msg : '') . '）';
    }
    $up = strtoupper($code);
    if (isset($map[$up])) return $map[$up] . '（' . $code . '）';
    return ($msg ?: '华为云 API 调用失败') . '（' . ($code ?: 'HTTP ' . $http) . '）';
}

/**
 * 通过 IAM 全局端点查询 ProjectId 归属的地域（前端填写 ProjectId 后自动定位区域用）。
 * 返回 ['region' => 'cn-east-3', 'displayName' => '华东-上海一']；无权限时抛 ApiError，前端回退为手动选择。
 */
function hw_project_info(string $projectId): array
{
    if ($projectId === '') throw new ApiError('请先填写 ProjectId');
    $r = hw_call('', 'iam', 'GET', '/v3/projects/' . rawurlencode($projectId), [], null, $projectId);
    $p = (array)($r['project'] ?? []);
    $region = (string)($p['name'] ?? '');
    if ($region === '') throw new ApiError('未查询到该 ProjectId 对应的地域，请手动选择地域');
    return ['region' => $region, 'displayName' => (string)($p['display_name'] ?? $region)];
}

/* ==========================================================================
 *  八、云厂商适配器（统一能力出口，便于后续扩展其他云）
 * ---------------------------------------------------------------------------
 *  约定：实例统一输出 id / name / region / public_ip / status / cpu / memory /
 *  bandwidth；状态码复用腾讯侧枚举（RUNNING / STOPPED / ...），中文文案由
 *  state_cn() 统一映射。快照 / 防火墙 / 监控输出结构与腾讯侧保持同构。
 * ========================================================================== */

interface CloudAdapter
{
    public function bootstrap(): array;
    public function instances(string $region): array;
    public function blueprints(string $region, string $instanceId): array;
    public function power(array $d): array;
    public function resetPassword(array $d): array;
    public function resetSystem(array $d): array;
    public function rename(array $d): array;
    public function snapshotList(array $d): array;
    public function snapshotCreate(array $d): array;
    public function snapshotDelete(array $d): array;
    public function snapshotApply(array $d): array;
    public function fwList(array $d): array;
    public function fwAdd(array $d): array;
    public function fwDel(array $d): array;
    public function monitor(array $d): array;
    public function testConn(array $d): array;
}

/** 工厂：按前端传参构造适配器（扩展新云时在此注册） */
function make_adapter(string $cloud): CloudAdapter
{
    switch ($cloud) {
        case 'huawei':
            return new HuaweiAdapter();
        case 'tencent':
        default:
            return new TencentAdapter();
    }
}

/* ----------------------------- 腾讯云适配器 -----------------------------
 *  完全复用原有 biz_* 业务函数与 TC3 签名（第三 / 五节），原有逻辑未做改动，
 *  仅作为适配器出口转发；密钥经 tc_cred_ak() / tc_cred_sk() 运行时注入。
 * ----------------------------------------------------------------------- */
class TencentAdapter implements CloudAdapter
{
    public function bootstrap(): array
    {
        $b = biz_bootstrap();
        // 是否已在服务端 index.php 配置区写死密钥（前端可不填）
        $b['backendCred'] = (TC_SECRET_ID !== '' && TC_SECRET_KEY !== '');
        return $b;
    }
    public function instances(string $region): array { return biz_instances($region); }
    public function blueprints(string $region, string $instanceId): array { return biz_blueprints($region, $instanceId); }
    public function power(array $d): array { return biz_power($d); }
    public function resetPassword(array $d): array { return biz_reset_password($d); }
    public function resetSystem(array $d): array { return biz_reset_system($d); }
    public function rename(array $d): array { return biz_rename($d); }
    public function snapshotList(array $d): array { return biz_snapshot_list($d); }
    public function snapshotCreate(array $d): array { return biz_snapshot_create($d); }
    public function snapshotDelete(array $d): array { return biz_snapshot_delete($d); }
    public function snapshotApply(array $d): array { return biz_snapshot_apply($d); }
    public function fwList(array $d): array { return biz_fw_list($d); }
    public function fwAdd(array $d): array { return biz_fw_add($d); }
    public function fwDel(array $d): array { return biz_fw_del($d); }
    public function monitor(array $d): array { return biz_monitor($d); }
    public function testConn(array $d): array
    {
        // 连通性测试：用当前密钥拉一次地域列表
        lh([], (string)($d['region'] ?? DEFAULT_REGION), 'DescribeRegions');
        return ['msg' => '腾讯云连通成功：密钥有效'];
    }
}

/* ----------------------------- 华为云适配器 -----------------------------
 *  通过 hw_call()（HwV4Sign 签名）调用华为云 ECS / EVS / VPC / CES / IMS
 *  OpenAPI。华为侧操作均为异步任务：接口返回成功仅代表指令受理，实际状态
 *  复用前端既有轮询逻辑（实例状态轮询 pollReinstall / 快照轮询 pollSnap）。
 * ----------------------------------------------------------------------- */
class HuaweiAdapter implements CloudAdapter
{
    /** 常用 ECS 地域静态表（华为云无免鉴权的地域列表接口） */
    public const REGIONS = [
        'cn-north-4'     => '华北-北京四',
        'cn-north-9'     => '华北-乌兰察布一',
        'cn-north-1'     => '华北-北京一',
        'cn-east-3'      => '华东-上海一',
        'cn-east-2'      => '华东-上海二',
        'cn-south-1'     => '华南-广州',
        'cn-southwest-2' => '西南-贵阳一',
        'cn-northwest-2' => '西北-西安',
        'ap-southeast-2' => '亚太-香港',
        'ap-southeast-3' => '亚太-新加坡',
        'ap-southeast-1' => '亚太-曼谷',
        'ru-north-2'     => '俄罗斯-莫斯科一',
        'eu-west-0'      => '欧洲-巴黎',
    ];

    public function bootstrap(): array
    {
        $regions = [];
        foreach (array_keys(self::REGIONS) as $k) {
            $regions[] = ['region' => $k, 'name' => self::REGIONS[$k]];
        }
        return [
            'cloud'   => 'huawei',
            'regions' => $regions,
            'region'  => HW_DEFAULT_REGION,
            // 是否已在服务端 index.php 配置区写死密钥（前端可不填）
            'backendCred' => (HW_AK !== '' && HW_SK !== '' && HW_PROJECT !== ''),
            'pwdRule' => [
                'min'    => 8,
                'max'    => 26,
                'strict' => 1,
                'desc'   => '8~26 位，需包含大写字母、小写字母、数字、特殊符号中的至少三类（华为云 ECS 要求）',
            ],
        ];
    }

    /** 华为云密码规则：8~26 位，至少三类字符，不含空格 */
    private function checkPwd(string $pwd): bool
    {
        $n = strlen($pwd);
        if ($n < 8 || $n > 26 || preg_match('/\s/', $pwd)) return false;
        $k = 0;
        if (preg_match('/[A-Z]/', $pwd)) $k++;
        if (preg_match('/[a-z]/', $pwd)) $k++;
        if (preg_match('/[0-9]/', $pwd)) $k++;
        if (preg_match('/[^A-Za-z0-9]/', $pwd)) $k++;
        return $k >= 3;
    }

    /** 状态统一映射：华为云 ECS 状态 → 面板统一状态码（复用腾讯侧枚举与中文文案） */
    private function normalizeStatus(string $st): array
    {
        static $map = [
            'ACTIVE'        => 'RUNNING',     // 运行中
            'SHUTOFF'       => 'STOPPED',     // 已关机
            'STOPPED'       => 'STOPPED',     // 部分接口返回 STOPPED
            'REBOOT'        => 'REBOOTING',   // 重启中
            'HARD_REBOOT'   => 'REBOOTING',
            'BUILD'         => 'PENDING',     // 创建中
            'REBUILD'       => 'RESETTING',   // 重装中
            'RESIZE'        => 'PENDING',
            'VERIFY_RESIZE' => 'PENDING',
            'MIGRATING'     => 'PENDING',
            'ERROR'         => 'LAUNCH_FAILED',
            'FROZEN'        => 'ISOLATED',    // 已冻结
            'DELETED'       => 'TERMINATING',
        ];
        $code = $map[$st] ?? $st;
        return ['code' => $code, 'cn' => state_cn($code), 'color' => state_color($code)];
    }

    /* ---- 实例列表：ECS GET /v1/{project_id}/cloudservers/detail ---- */
    public function instances(string $region): array
    {
        $list = [];
        $page = 1;
        do {
            $r = hw_call($region, 'ecs', 'GET', '/v1/{project_id}/cloudservers/detail', ['limit' => 100, 'offset' => $page]);
            foreach ((array)($r['servers'] ?? []) as $s) $list[] = $s;
            $total = (int)($r['count'] ?? count($list));
            $page++;
        } while (count($list) < $total && $page <= 200);

        // 公网带宽：从 EIP 列表按 IP 反查带宽上限（best-effort，失败不影响列表）
        $eipMap = [];
        try {
            $r = hw_call($region, 'vpc', 'GET', '/v1/{project_id}/publicips');
            foreach ((array)($r['publicips'] ?? []) as $p) {
                $eipMap[(string)($p['public_ip_address'] ?? '')] = (int)($p['bandwidth_size'] ?? 0);
            }
        } catch (ApiError $e) {
            // 忽略：EIP 查询失败时带宽显示 0
        }

        // 到期时间 / 计费模式：BSS 包年包月资源查询（best-effort，子账号无权限时静默跳过）
        $expireMap = [];
        try {
            $ids = [];
            foreach ($list as $s) {
                $x = (string)($s['id'] ?? '');
                if ($x !== '') $ids[] = $x;
            }
            foreach (array_chunk($ids, 50) as $chunk) {
                $r = hw_call('', 'bss', 'POST', '/v2/orders/suscriptions/resources/query', [],
                    ['resource_ids' => $chunk, 'offset' => 0, 'limit' => 100]);
                foreach ((array)($r['data'] ?? []) as $res) {
                    $rid = (string)($res['resource_id'] ?? '');
                    if ($rid !== '') $expireMap[$rid] = $res;
                }
            }
        } catch (ApiError $e) {
            // 忽略：无 BSS 查询权限时到期时间显示 -
        }

        $out = [];
        foreach ($list as $s) $out[] = $this->normalizeInstance($s, $region, $eipMap, $expireMap);
        return ['instances' => $out, 'region' => $region];
    }

    /** ECS server → 统一实例结构 */
    private function normalizeInstance(array $s, string $region, array $eipMap, array $expireMap = []): array
    {
        $pub = [];
        $priv = [];
        foreach ((array)($s['addresses'] ?? []) as $nets) {
            foreach ((array)$nets as $a) {
                $addr = (string)($a['addr'] ?? '');
                if ($addr === '') continue;
                $type = (string)($a['OS-EXT-IPS:type'] ?? '');
                if ($type === 'floating') $pub[] = $addr;
                elseif ($type === 'fixed') $priv[] = $addr;
                elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $pub[] = $addr;
                else $priv[] = $addr;
            }
        }
        $flavor = (array)($s['flavor'] ?? []);
        $meta   = (array)($s['metadata'] ?? []);
        $st     = $this->normalizeStatus((string)($s['status'] ?? ''));
        $pubIp  = $pub[0] ?? '';
        $id     = (string)($s['id'] ?? '');

        // 到期时间 / 计费模式（BSS 包年包月资源，规格编码同时可推断系统类型）
        $exp  = (array)($expireMap[$id] ?? []);
        $expireTime = (string)($exp['expire_time'] ?? '');
        $spec = (string)($exp['resource_spec_code'] ?? '');
        $osGuess = stripos($spec, '.windows') !== false ? 'Windows' : (stripos($spec, '.linux') !== false ? 'Linux' : '');

        return [
            // 统一输出字段（适配器约定）
            'id'        => $id,
            'name'      => (string)($s['name'] ?? ''),
            'region'    => $region,
            'public_ip' => $pubIp,
            'status'    => $st['code'],
            'cpu'       => (int)($flavor['vcpus'] ?? 0),
            'memory'    => (int)round(((int)($flavor['ram'] ?? 0)) / 1024), // MB → GB
            'bandwidth' => $eipMap[$pubIp] ?? 0,
            // 兼容前端既有渲染的扩展字段
            'state'     => $st['code'],
            'stateCn'   => $st['cn'],
            'stateColor'=> $st['color'],
            'stateRaw'  => (string)($s['status'] ?? ''),
            'public'    => $pub,
            'private'   => $priv,
            'ipv6'      => [],
            'zone'      => (string)($s['OS-EXT-AZ:availability_zone'] ?? ''),
            'os'        => (string)($meta['os_name'] ?? $meta['metering.image_name'] ?? '') ?: $osGuess,
            'platform'  => '',
            'blueprint' => (string)($meta['metering.image_id'] ?? ''),
            'bundle'    => (string)($flavor['id'] ?? ''),
            'disk'      => 0,
            'charge'    => $expireTime !== '' ? 'PREPAID' : '',
            'expired'   => $expireTime,
            'created'   => (string)($s['created'] ?? ''),
            'traffic'   => null, // 华为侧无轻量流量包概念，前端隐藏流量卡片
        ];
    }

    /* ---- 开机 / 关机 / 重启：ECS POST /v1/{project_id}/cloudservers/action（异步） ---- */
    public function power(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $op     = (string)$d['op'];
        $bodyMap = [
            // SOFT 为软操作；如需强制断电可扩展为 HARD
            'start'  => ['os-start' => ['servers' => [['id' => $id]]]],
            'stop'   => ['os-stop'  => ['servers' => [['id' => $id]], 'type' => 'SOFT']],
            'reboot' => ['reboot'   => ['type' => 'SOFT', 'servers' => [['id' => $id]]]],
        ];
        if (!isset($bodyMap[$op])) throw new ApiError('未知的操作类型');
        hw_call($region, 'ecs', 'POST', '/v1/{project_id}/cloudservers/action', [], $bodyMap[$op]);
        $cn = ['start' => '开机', 'stop' => '关机', 'reboot' => '重启'][$op];
        write_log('EcsPower:' . $op, $region . '/' . $id, 'success', $cn . '指令已下发（异步）');
        return ['msg' => $cn . '指令已下发，实例状态稍后自动刷新'];
    }

    /* ---- 重置密码：ECS POST /v1/{project_id}/cloudservers/{id}/os-reset-password（异步） ---- */
    public function resetPassword(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $pwd    = (string)$d['password'];
        if (!$this->checkPwd($pwd)) {
            throw new ApiError('密码不符合华为云要求：8~26 位，需包含大写字母、小写字母、数字、特殊符号中的至少三类');
        }
        hw_call($region, 'ecs', 'POST', '/v1/{project_id}/cloudservers/' . rawurlencode($id) . '/os-reset-password', [],
            ['reset-password' => ['password' => $pwd, 'is_check_password' => true]]);
        write_log('OsResetPassword', $region . '/' . $id, 'success', '重置实例密码（异步）');
        return ['msg' => '重置密码指令已下发，通常几分钟内生效；需实例已安装密码重置插件（华为云公共镜像默认内置）'];
    }

    /* ---- 改名：ECS PUT /v1/{project_id}/cloudservers/{id} ---- */
    public function rename(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $name   = trim((string)$d['name']);
        if ($name === '') throw new ApiError('实例名称不能为空');
        if (str_len($name) > 64) throw new ApiError('实例名称过长（最多 64 个字符）');
        hw_call($region, 'ecs', 'PUT', '/v1/{project_id}/cloudservers/' . rawurlencode($id), [], ['server' => ['name' => $name]]);
        write_log('UpdateServerName', $region . '/' . $id, 'success', '重命名为 ' . $name);
        return ['msg' => '实例名称已更新'];
    }

    /* ---- 镜像列表：ECS 宿主路由 GET /v2/{project_id}/images（IMS 独立路由在部分网关未发布） ---- */
    public function blueprints(string $region, string $instanceId): array
    {
        $out = [];
        $r = hw_call($region, 'ecs', 'GET', '/v2/{project_id}/images', ['__imagetype' => 'gold', 'limit' => 200]);
        foreach ((array)($r['images'] ?? []) as $im) {
            $name = (string)($im['name'] ?? '');
            $platform = (string)($im['__platform'] ?? '') ?: (stripos($name, 'windows') !== false ? 'Windows' : 'Linux');
            $out[] = [
                'id'         => (string)($im['id'] ?? ''),
                'title'      => $name,
                'version'    => '',
                'desc'       => (string)($im['__description'] ?? ''),
                'os'         => (string)($im['__os_version'] ?? $name),
                'platform'   => $platform,
                'type'       => 'GOLD',
                'typeCn'     => '公共镜像',
                'resettable' => true,
                'note'       => '',
            ];
        }
        usort($out, function ($a, $b) {
            $w = ['Linux' => 0, 'Windows' => 1];
            $wa = $w[$a['platform']] ?? 5;
            $wb = $w[$b['platform']] ?? 5;
            if ($wa !== $wb) return $wa <=> $wb;
            return strcmp($a['title'], $b['title']);
        });
        return ['blueprints' => $out];
    }

    /* ---- 重装系统（切换操作系统）：ECS POST /v1/{project_id}/cloudservers/{id}/changeos（异步，要求实例关机） ---- */
    public function resetSystem(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $bp     = (string)$d['blueprintId'];
        if ($bp === '') throw new ApiError('请选择要安装的镜像');
        $p = ['changeos' => ['imageid' => $bp]];
        if (!empty($d['password'])) {
            if (!$this->checkPwd((string)$d['password'])) {
                throw new ApiError('新密码不符合华为云要求：8~26 位，需包含大写字母、小写字母、数字、特殊符号中的至少三类');
            }
            $p['changeos']['adminpass'] = (string)$d['password'];
        }
        hw_call($region, 'ecs', 'POST', '/v1/{project_id}/cloudservers/' . rawurlencode($id) . '/changeos', [], $p);
        write_log('ChangeOS', $region . '/' . $id, 'success', '重装系统，镜像 ' . $bp);
        return ['msg' => '重装任务已下发（华为云为异步任务），通常 3~10 分钟完成；页面将自动轮询实例状态'];
    }

    /** 查询实例挂载的云盘：[volumeId => bootIndex]
     *  统一走 nova 兼容路由 os-volume_attachments（/v1/.../block_devices 在部分网关未发布）；
     *  /dev/vda 视为系统盘（bootIndex 0） */
    private function blockDevices(string $region, string $id): array
    {
        $r = hw_call($region, 'ecs', 'GET', '/v2.1/{project_id}/servers/' . rawurlencode($id) . '/os-volume_attachments');
        $out = [];
        foreach ((array)($r['volumeAttachments'] ?? []) as $v) {
            $vol = (string)($v['volumeId'] ?? '');
            if ($vol === '') continue;
            $out[$vol] = ((string)($v['device'] ?? '') === '/dev/vda') ? 0 : -1;
        }
        return $out;
    }

    /** 取实例系统盘（bootIndex=0）的卷 ID */
    private function systemVolume(string $region, string $id): string
    {
        foreach ($this->blockDevices($region, $id) as $vol => $boot) {
            if ($boot === 0) return $vol;
        }
        throw new ApiError('未找到实例的系统盘卷（无法定位系统盘 VolumeId）');
    }

    /* ---- 快照列表：EVS GET /v2/{project_id}/cloudsnapshots/detail ---- */
    public function snapshotList(array $d): array
    {
        $region = (string)($d['region'] ?? HW_DEFAULT_REGION);
        $iid    = (string)($d['instanceId'] ?? '');

        $query = ['limit' => 1000];
        if ($iid !== '') $query['volume_id'] = $this->systemVolume($region, $iid);
        $r = hw_call($region, 'evs', 'GET', '/v2/{project_id}/cloudsnapshots/detail', $query);

        $list = [];
        foreach ((array)($r['snapshots'] ?? []) as $x) $list[] = $this->normalizeSnapshot($x, $iid);

        if ($iid === '' && $list) {
            // 全量列表时用 卷ID → 实例 反查归属（逐实例查询，best-effort）
            $map = [];
            try {
                $r2 = hw_call($region, 'ecs', 'GET', '/v1/{project_id}/cloudservers/detail', ['limit' => 200]);
                foreach ((array)($r2['servers'] ?? []) as $s) {
                    $sid = (string)($s['id'] ?? '');
                    if ($sid === '') continue;
                    try {
                        foreach ($this->blockDevices($region, $sid) as $vol => $boot) $map[$vol] = $sid;
                    } catch (ApiError $e) {
                        continue;
                    }
                }
            } catch (ApiError $e) {
                // 反查失败不影响列表展示
            }
            foreach ($list as &$sn) $sn['instanceId'] = $map[$sn['volumeId']] ?? '';
            unset($sn);
        }

        usort($list, fn($a, $b) => strcmp($b['created'], $a['created']));
        return ['snapshots' => $list, 'region' => $region];
    }

    /** EVS 云快照 → 统一快照结构（状态统一映射到腾讯侧枚举） */
    private function normalizeSnapshot(array $x, string $instanceId): array
    {
        static $m = [
            'creating'       => 'CREATING',
            'backing-up'     => 'CREATING',
            'available'      => 'NORMAL',
            'error'          => 'ERROR',
            'deleting'       => 'DELETING',
            'error_deleting' => 'DELETING',
            'restoring'      => 'ROLLBACKING',
            'rollbacking'    => 'ROLLBACKING',
        ];
        $st   = (string)($x['status'] ?? '');
        $code = $m[$st] ?? strtoupper($st ?: 'UNKNOWN');
        return [
            'id'          => (string)($x['id'] ?? ''),
            'name'        => (string)($x['name'] ?? ''),
            'volumeId'    => (string)($x['volume_id'] ?? ''),
            'diskId'      => (string)($x['volume_id'] ?? ''),
            'diskUsage'   => 'SYSTEM_DISK',
            'diskUsageCn' => '系统盘',
            'size'        => (int)($x['size'] ?? 0),
            'state'       => $code,
            'stateCn'     => ['NORMAL' => '可用', 'CREATING' => '创建中', 'ROLLBACKING' => '回滚中', 'DELETING' => '删除中', 'ERROR' => '错误'][$code] ?? $code,
            'percent'     => 0,
            'created'     => (string)($x['created_at'] ?? ''),
            'instanceId'  => $instanceId,
        ];
    }

    /* ---- 创建快照：EVS POST /v2/{project_id}/cloudsnapshots（异步，针对系统盘） ---- */
    public function snapshotCreate(array $d): array
    {
        $region = (string)$d['region'];
        $iid    = (string)$d['instanceId'];
        $name   = trim((string)($d['name'] ?? ''));
        if ($name === '') $name = 'snap-' . date('Ymd-His');
        if (!preg_match('/^[a-zA-Z0-9._\-\x{4e00}-\x{9fa5}]{1,64}$/u', $name)) {
            throw new ApiError('快照名称只能包含中文、字母、数字、点、下划线、短横线，且不超过 64 个字符');
        }
        $vol = $this->systemVolume($region, $iid);
        hw_call($region, 'evs', 'POST', '/v2/{project_id}/cloudsnapshots', [],
            ['snapshot' => ['volume_id' => $vol, 'name' => $name, 'description' => '由面板创建 ' . date('Y-m-d H:i:s')]]);
        write_log('CreateCloudSnapshot', $region . '/' . $iid, 'success', '创建系统盘快照 ' . $name);
        return ['msg' => '快照创建任务已下发（异步），通常需要数分钟（耗时与磁盘大小有关）'];
    }

    /* ---- 删除快照：EVS DELETE /v2/{project_id}/cloudsnapshots/{id}（异步） ---- */
    public function snapshotDelete(array $d): array
    {
        $region = (string)$d['region'];
        $ids = array_values(array_filter((array)($d['snapshotIds'] ?? []), fn($x) => is_string($x) && $x !== ''));
        if (!$ids) throw new ApiError('请先选择要删除的快照');
        foreach ($ids as $sid) {
            hw_call($region, 'evs', 'DELETE', '/v2/{project_id}/cloudsnapshots/' . rawurlencode($sid));
        }
        write_log('DeleteCloudSnapshot', $region . '/' . implode(',', $ids), 'success', '删除快照 ' . count($ids) . ' 个（异步）');
        return ['msg' => '已下发删除 ' . count($ids) . ' 个快照的任务'];
    }

    /* ---- 回滚快照：EVS POST /v2/{project_id}/cloudsnapshots/{id}/rollback（异步，要求实例关机） ---- */
    public function snapshotApply(array $d): array
    {
        $region = (string)$d['region'];
        $iid    = (string)$d['instanceId'];
        $sid    = (string)$d['snapshotId'];
        if ($sid === '') throw new ApiError('请选择要回滚的快照');
        // 回滚目标卷：优先取快照自身记录的 volume_id，失败回退到实例系统盘
        $vol = '';
        try {
            $r   = hw_call($region, 'evs', 'GET', '/v2/{project_id}/cloudsnapshots/' . rawurlencode($sid));
            $vol = (string)($r['snapshot']['volume_id'] ?? '');
        } catch (ApiError $e) {
            // 回退
        }
        if ($vol === '') $vol = $this->systemVolume($region, $iid);
        hw_call($region, 'evs', 'POST', '/v2/{project_id}/cloudsnapshots/' . rawurlencode($sid) . '/rollback', [],
            ['rollback' => ['volume_id' => $vol]]);
        write_log('RollbackCloudSnapshot', $region . '/' . $iid, 'success', '回滚到快照 ' . $sid);
        return ['msg' => '回滚任务已下发。回滚会覆盖系统盘当前数据；华为云要求实例处于关机状态，实例将短暂重启'];
    }

    /** 实例绑定安全组名称 → ID 列表（VPC） */
    private function instanceSecurityGroups(string $region, string $id): array
    {
        $names = [];
        $r = hw_call($region, 'ecs', 'GET', '/v1/{project_id}/cloudservers/detail', ['limit' => 200]);
        foreach ((array)($r['servers'] ?? []) as $s) {
            if ((string)($s['id'] ?? '') !== $id) continue;
            foreach ((array)($s['security_groups'] ?? []) as $g) {
                $names[] = (string)($g['name'] ?? '');
            }
        }
        $names = array_values(array_filter($names));
        if (!$names) throw new ApiError('未找到实例绑定的安全组');
        $r2 = hw_call($region, 'vpc', 'GET', '/v1/{project_id}/security-groups', ['limit' => 200]);
        $ids = [];
        foreach ((array)($r2['security_groups'] ?? []) as $g) {
            if (in_array((string)($g['name'] ?? ''), $names, true)) $ids[] = (string)($g['id'] ?? '');
        }
        return array_values(array_filter($ids));
    }

    /* ---- 安全组规则列表：VPC GET /v1/{project_id}/security-group-rules（入方向） ---- */
    public function fwList(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $sgIds  = $this->instanceSecurityGroups($region, $id);

        $rules = [];
        foreach ($sgIds as $sg) {
            $r = hw_call($region, 'vpc', 'GET', '/v1/{project_id}/security-group-rules',
                ['security_group_id' => $sg, 'direction' => 'ingress', 'limit' => 200]);
            foreach ((array)($r['security_group_rules'] ?? []) as $x) {
                if ((string)($x['direction'] ?? '') !== 'ingress') continue;
                $proto = (string)($x['protocol'] ?? '');
                $min   = $x['port_range_min'] ?? null;
                $max   = $x['port_range_max'] ?? null;
                if ($min === null && $max === null) $port = 'ALL';
                elseif ($min === $max) $port = (string)$min;
                else $port = $min . '-' . $max;
                $remote = (string)($x['remote_ip_prefix'] ?? '');
                if ($remote === '' && !empty($x['remote_group_id'])) $remote = '安全组引用';
                $rules[] = [
                    'protocol' => $proto === '' ? 'ALL' : strtoupper($proto),
                    'port'     => $port,
                    'cidr'     => $remote ?: '-',
                    'action'   => 'ACCEPT', // 华为安全组入方向规则只有放行语义
                    'desc'     => '',
                    '_id'      => (string)($x['id'] ?? ''),
                ];
            }
        }
        return ['rules' => $rules, 'version' => 0];
    }

    /* ---- 新增安全组规则：VPC POST /v1/{project_id}/security-group-rules ---- */
    public function fwAdd(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $r      = (array)($d['rule'] ?? []);
        if (strtoupper(trim((string)($r['action'] ?? 'ACCEPT'))) === 'DROP') {
            throw new ApiError('华为云安全组规则只有「放行」语义，不支持拒绝（DROP）规则');
        }
        $sgIds = $this->instanceSecurityGroups($region, $id);
        $proto = strtolower(trim((string)($r['protocol'] ?? 'TCP')));
        $port  = strtoupper(trim((string)($r['port'] ?? 'ALL')));
        $cidr  = trim((string)($r['cidr'] ?? '0.0.0.0/0'));
        if ($cidr === '') $cidr = '0.0.0.0/0';

        $rule = [
            'direction'         => 'ingress',
            'ethertype'         => strpos($cidr, ':') !== false ? 'IPv6' : 'IPv4',
            'security_group_id' => $sgIds[0],
            'remote_ip_prefix'  => $cidr,
            'protocol'          => $proto === 'all' ? null : $proto,
        ];
        if ($proto === 'tcp' || $proto === 'udp') {
            if ($port === '' || $port === 'ALL') {
                $min = null; $max = null;
            } elseif (preg_match('/^(\d{1,5})-(\d{1,5})$/', $port, $mm)) {
                $min = (int)$mm[1]; $max = (int)$mm[2];
            } elseif (preg_match('/^\d{1,5}$/', $port)) {
                $min = (int)$port; $max = $min;
            } else {
                throw new ApiError('端口格式不正确。华为安全组仅支持单端口或范围（如 80、8000-8080、ALL），不支持逗号分隔多端口');
            }
            if ($min !== null && ($min < 1 || $max > 65535 || $min > $max)) {
                throw new ApiError('端口范围不正确（1~65535）');
            }
            $rule['port_range_min'] = $min;
            $rule['port_range_max'] = $max;
        } elseif (in_array($proto, ['icmp', 'icmpv6'], true)) {
            $rule['port_range_min'] = null;
            $rule['port_range_max'] = null;
        } elseif ($proto !== 'all') {
            throw new ApiError('协议取值只能为 TCP / UDP / ICMP / ICMPv6 / ALL');
        }

        hw_call($region, 'vpc', 'POST', '/v1/{project_id}/security-group-rules', [],
            ['security_group_rule' => $rule]);
        write_log('CreateSecurityGroupRule', $region . '/' . $id, 'success', '新增放行规则 ' . $proto . '/' . $port . ' from ' . $cidr);
        return ['msg' => '安全组规则已添加'];
    }

    /* ---- 删除安全组规则：VPC DELETE /v1/{project_id}/security-group-rules/{rule_id} ---- */
    public function fwDel(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $rule   = (array)($d['rule'] ?? []);
        $rid    = (string)($rule['_id'] ?? '');
        if ($rid === '') throw new ApiError('缺少规则 ID，请刷新规则列表后重试');
        hw_call($region, 'vpc', 'DELETE', '/v1/{project_id}/security-group-rules/' . rawurlencode($rid));
        write_log('DeleteSecurityGroupRule', $region . '/' . $id, 'success', '删除规则 ' . $rid);
        return ['msg' => '安全组规则已删除'];
    }

    /* ---- 监控：CES GET /V1.0/{project_id}/metric-data（SYS.ECS 命名空间） ---- */
    public function monitor(array $d): array
    {
        $region = (string)$d['region'];
        $id     = (string)$d['instanceId'];
        $range  = (string)($d['range'] ?? '24h');

        $hours = ($range === '7d') ? 168 : 24;
        $to    = (int)(microtime(true) * 1000);
        $from  = $to - $hours * 3600 * 1000;
        // CES 允许的 period 与查询跨度相关（跨度 ≤ period×100）：24h 用 1200s、7d 用 14400s
        $periods = ($range === '7d') ? [14400, 86400] : [1200, 3600];

        // 华为云 SYS.ECS 指标；bit/s → Mbps 需 ÷1e6；内存指标需实例安装 CES Agent 上报
        $metrics = [
            ['key' => 'cpu',   'metric' => 'cpu_util',  'unit' => '%',    'name' => 'CPU 使用率', 'scale' => 1],
            ['key' => 'mem',   'metric' => 'mem_used_percent', 'unit' => '%', 'name' => '内存使用率', 'scale' => 1],
            ['key' => 'bwout', 'metric' => 'network_outgoing_bytes_aggregate_rate', 'unit' => 'Mbps', 'name' => '公网出带宽', 'scale' => 0.000001],
            ['key' => 'bwin',  'metric' => 'network_incoming_bytes_aggregate_rate',  'unit' => 'Mbps', 'name' => '公网入带宽', 'scale' => 0.000001],
        ];

        $out = [];
        foreach ($metrics as $m) {
            $data = ['points' => [], 'unit' => $m['unit'], 'name' => $m['name']];
            foreach ($periods as $period) {
                try {
                    $r = hw_call($region, 'ces', 'GET', '/V1.0/{project_id}/metric-data', [
                        'namespace'   => 'SYS.ECS',
                        'metric_name' => $m['metric'],
                        'dim.0'       => 'instance_id,' . $id,
                        'from'        => $from,
                        'to'          => $to,
                        'period'      => $period,
                        'filter'      => 'average',
                    ]);
                } catch (ApiError $e) {
                    continue;
                }
                // CES V1.0 metric-data 返回扁平结构 {"datapoints":[...]}（无 metrics 包装层）
                $dps = (array)($r['datapoints'] ?? []);
                if ($dps) {
                    $ts = [];
                    $vs = [];
                    foreach ($dps as $p) {
                        $ts[] = (int)((int)($p['timestamp'] ?? 0) / 1000); // CES 返回毫秒，sample_points 以秒计算
                        $vs[] = $p['average'] ?? null;
                    }
                    $data['points'] = sample_points($ts, $vs, $m['scale'], 500);
                    $data['period'] = $period;
                    break;
                }
            }
            $out[$m['key']] = $data;
        }
        $empty = true;
        foreach ($out as $v) { if (!empty($v['points'])) $empty = false; }
        return [
            'series' => $out,
            'range'  => $range,
            'empty'  => $empty,
            'note'   => $empty ? '未拉取到监控数据：内存与网络指标需实例已安装华为云 Agent（UniAgent + CES Agent）并上报数据，或该时间段内无数据' : '',
        ];
    }

    /* ---- 连通性测试：用当前 AK/SK/ProjectId 查一次实例列表 ---- */
    public function testConn(array $d): array
    {
        $region = (string)($d['region'] ?? HW_DEFAULT_REGION);
        hw_call($region, 'ecs', 'GET', '/v1/{project_id}/cloudservers/detail', ['limit' => 1]);
        return ['msg' => '华为云连通成功：AK / SK / ProjectId 有效'];
    }
}

/* ==========================================================================
 *  九、登录 / 鉴权 / 路由
 * ========================================================================== */

function check_login_pwd(string $input): bool
{
    $cfg = PANEL_PASSWORD;
    if (strpos($cfg, 'sha256:') === 0) {
        return hash_equals(substr($cfg, 7), hash('sha256', $input));
    }
    return hash_equals($cfg, $input);
}

function do_login(array $d): void
{
    $now = time();
    if (!empty($_SESSION['lock_until']) && $now < (int)$_SESSION['lock_until']) {
        json_out(['ok' => false, 'msg' => '登录失败次数过多，请 ' . ((int)$_SESSION['lock_until'] - $now) . ' 秒后再试']);
    }
    $pwd = (string)($d['password'] ?? '');
    if (check_login_pwd($pwd)) {
        session_regenerate_id(true);
        $_SESSION['auth']      = 1;
        $_SESSION['auth_time'] = $now;
        $_SESSION['csrf']      = bin2hex(random_bytes(16));
        $_SESSION['fail']      = 0;
        unset($_SESSION['lock_until']);
        write_log('PanelLogin', '-', 'success', '面板登录成功');
        json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    $_SESSION['fail'] = (int)($_SESSION['fail'] ?? 0) + 1;
    write_log('PanelLogin', '-', 'fail', '密码错误，第 ' . $_SESSION['fail'] . ' 次');
    if ($_SESSION['fail'] >= 5) {
        $_SESSION['lock_until'] = $now + 300;
        json_out(['ok' => false, 'msg' => '连续 5 次密码错误，已锁定 5 分钟']);
    }
    json_out(['ok' => false, 'msg' => '密码错误，还可尝试 ' . (5 - (int)$_SESSION['fail']) . ' 次']);
}

function require_auth(): void
{
    if (empty($_SESSION['auth'])) {
        http_response_code(401);
        json_out(['ok' => false, 'needLogin' => true, 'msg' => '登录已失效，请重新登录']);
    }
    $_SESSION['auth_time'] = time();
}

function check_csrf(array $d): void
{
    $token = (string)($d['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        http_response_code(403);
        json_out(['ok' => false, 'msg' => '安全校验失败（CSRF），请刷新页面重试']);
    }
}

function dispatch(string $action, array $d): void
{
    // 云厂商切换：默认 tencent（原有腾讯逻辑原样复用），huawei 走华为适配器
    $cloud = (($d['cloud'] ?? 'tencent') === 'huawei') ? 'huawei' : 'tencent';
    $GLOBALS['CLOUD'] = $cloud;
    $region = (string)($d['region'] ?? ($cloud === 'huawei' ? HW_DEFAULT_REGION : DEFAULT_REGION));

    // 前端 localStorage 传入的临时密钥：仅注入当前请求内存，不落盘 / 不写会话 / 不入日志
    $cred = (array)($d['cred'] ?? []);
    if ($cloud === 'tencent') {
        $GLOBALS['TC_CRED'] = ['ak' => trim((string)($cred['ak'] ?? '')), 'sk' => trim((string)($cred['sk'] ?? ''))];
    } else {
        $GLOBALS['HW_CRED'] = [
            'ak'      => trim((string)($cred['ak'] ?? '')),
            'sk'      => trim((string)($cred['sk'] ?? '')),
            'project' => trim((string)($cred['project'] ?? '')),
        ];
    }

    try {
        $ad = make_adapter($cloud);
        switch ($action) {
            case 'bootstrap':   json_out(['ok' => true, 'data' => $ad->bootstrap()]);
            case 'instances':   json_out(['ok' => true, 'data' => $ad->instances($region)]);
            case 'blueprints':  json_out(['ok' => true, 'data' => $ad->blueprints($region, (string)($d['instanceId'] ?? ''))]);
            case 'power':       json_out(['ok' => true, 'data' => $ad->power($d)]);
            case 'reset_pwd':   json_out(['ok' => true, 'data' => $ad->resetPassword($d)]);
            case 'reset_system':json_out(['ok' => true, 'data' => $ad->resetSystem($d)]);
            case 'rename':      json_out(['ok' => true, 'data' => $ad->rename($d)]);
            case 'fw_list':     json_out(['ok' => true, 'data' => $ad->fwList($d)]);
            case 'snap_list':   json_out(['ok' => true, 'data' => $ad->snapshotList($d)]);
            case 'snap_create': json_out(['ok' => true, 'data' => $ad->snapshotCreate($d)]);
            case 'snap_del':    json_out(['ok' => true, 'data' => $ad->snapshotDelete($d)]);
            case 'snap_apply':  json_out(['ok' => true, 'data' => $ad->snapshotApply($d)]);
            case 'fw_add':      json_out(['ok' => true, 'data' => $ad->fwAdd($d)]);
            case 'fw_del':      json_out(['ok' => true, 'data' => $ad->fwDel($d)]);
            case 'monitor':     json_out(['ok' => true, 'data' => $ad->monitor($d)]);
            case 'test_conn':   json_out(['ok' => true, 'data' => $ad->testConn($d)]);
            case 'hw_project':  json_out(['ok' => true, 'data' => hw_project_info(trim((string)($d['projectId'] ?? '')))]);
            case 'log_list':    json_out(['ok' => true, 'data' => ['logs' => read_logs(200), 'file' => LOG_FILE]]);
            default:            json_out(['ok' => false, 'msg' => '未知操作：' . $action]);
        }
    } catch (ApiError $e) {
        write_log($action, $region . '/' . (string)($d['instanceId'] ?? '-'), 'fail', $e->getMessage() . ' [' . $e->tcCode . ']');
        json_out(['ok' => false, 'msg' => $e->getMessage(), 'code' => $e->tcCode, 'requestId' => $e->requestId]);
    } catch (Throwable $e) {
        write_log($action, $region . '/' . (string)($d['instanceId'] ?? '-'), 'fail', 'PHP 异常：' . $e->getMessage());
        json_out(['ok' => false, 'msg' => '服务端异常：' . $e->getMessage()]);
    }
}

// ---------------- 路由入口 ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    $action = (string)($data['action'] ?? '');

    if ($action === 'login') {
        do_login($data);
    }
    if ($action === 'logout') {
        $_SESSION = [];
        json_out(['ok' => true]);
    }
    require_auth();
    check_csrf($data);
    dispatch($action, $data);
}

// GET：退出登录
if (isset($_GET['logout'])) {
    $_SESSION = [];
    header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (empty($_SESSION['auth'])) {
    render_login();
    exit;
}

$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
render_page();
exit;

/* ==========================================================================
 *  十、页面：登录
 * ========================================================================== */
function render_login(): void
{
    $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>轻量运维面板 · 登录</title>
<script src="__CDN_TAILWIND__"></script>
<style>
  body{background:#0f172a}
  .card{backdrop-filter:blur(6px)}
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="card bg-white/95 rounded-2xl shadow-2xl p-8">
      <div class="text-center mb-6">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-blue-600 text-white flex items-center justify-center text-2xl font-bold">LH</div>
        <h1 class="mt-4 text-xl font-bold text-slate-800">轻量服务器运维面板</h1>
        <p class="text-sm text-slate-500 mt-1">Tencent Lighthouse / Huawei ECS Panel</p>
      </div>
      <div id="tip" class="hidden mb-4 text-sm rounded-xl px-4 py-3"></div>
      <form id="loginForm" autocomplete="off">
        <label class="block text-sm font-medium text-slate-700 mb-2">面板登录密码</label>
        <input type="password" id="pwd" name="password" placeholder="请输入面板密码"
          class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <button type="submit" id="btn"
          class="mt-5 w-full rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 transition disabled:opacity-60">
          登 录
        </button>
      </form>
      <p class="mt-6 text-xs leading-relaxed text-slate-400">
        本面板使用云 API 密钥直连云 API，请妥善保管面板密码。云密钥仅保存在你浏览器的 localStorage 中，后端不持久化存储。
      </p>
    </div>
  </div>
<script>
const form=document.getElementById('loginForm'),pwd=document.getElementById('pwd'),btn=document.getElementById('btn'),tip=document.getElementById('tip');
function show(msg,ok){tip.className='mb-4 text-sm rounded-xl px-4 py-3 '+(ok?'bg-green-50 text-green-700':'bg-red-50 text-red-700');tip.textContent=msg;tip.classList.remove('hidden');}
form.addEventListener('submit',async e=>{
  e.preventDefault();
  if(!pwd.value){show('请输入密码',false);return;}
  btn.disabled=true;btn.textContent='登录中...';
  try{
    const r=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'login',password:pwd.value})});
    const j=await r.json();
    if(j.ok){show('登录成功，正在进入...',true);setTimeout(()=>location.reload(),300);}
    else{show(j.msg||'登录失败',false);btn.disabled=false;btn.textContent='登 录';}
  }catch(err){show('请求失败：'+err.message,false);btn.disabled=false;btn.textContent='登 录';}
});
pwd.focus();
</script>
</body></html>
HTML;
    echo str_replace('__CDN_TAILWIND__', h(CDN_TAILWIND), $html);
    exit;
}

/* ==========================================================================
 *  十一、页面：主面板
 * ========================================================================== */
function render_page(): void
{
    $cfg = [
        'csrf'     => $_SESSION['csrf'],
        'region'   => DEFAULT_REGION,
        'authMode' => AUTH_MODE,
        'pwdDesc'  => password_desc(),
        'pwdMin'   => PASSWORD_STRICT ? 8 : 8,
        'pwdMax'   => PASSWORD_STRICT ? 30 : 16,
        'pwdStrict'=> PASSWORD_STRICT ? 1 : 0,
        'logFile'  => LOG_FILE,
        'logWritable' => (is_writable(dirname(LOG_FILE)) || (is_file(LOG_FILE) && is_writable(LOG_FILE))) ? 1 : 0,
    ];
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>轻量服务器运维面板</title>
<script src="__CDN_TAILWIND__"></script>
<script src="__CDN_CHARTJS__"></script>
<style>
  body{background:#f1f5f9}
  .tab-active{background:#2563eb!important;color:#fff!important}
  ::-webkit-scrollbar{width:8px;height:8px}
  ::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:8px}
  .mask{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:16px}
  .mask-box{background:#fff;border-radius:16px;max-width:640px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.25)}
  .no-tw .tw-hide{display:none}
  .fade-in{animation:fade .18s ease}
  @keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  /* ---- 移动端适配 ---- */
  html{-webkit-text-size-adjust:100%;text-size-adjust:100%}
  @media (max-width:640px){
    header .justify-between{flex-wrap:wrap}          /* 顶栏允许换行，避免云切换/按钮被挤压 */
    .mask{padding:10px}
    .mask-box{border-radius:14px}
    input,select{font-size:16px !important}           /* 防 iOS 聚焦时页面自动放大 */
    td,th{word-break:break-word}
    .tab-btn,.cloud-tab{padding-left:12px;padding-right:12px}
  }
</style>
</head>
<body class="text-slate-800">

<!-- 顶部 -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-30">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
      <div class="w-9 h-9 shrink-0 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold">LH</div>
      <div class="min-w-0">
        <div class="font-bold text-slate-800 leading-tight truncate">轻量服务器运维面板</div>
        <div class="text-xs text-slate-400 truncate">Lighthouse / ECS · API 直连运维</div>
      </div>
    </div>
    <!-- 云厂商切换：腾讯轻量云 / 华为云（沿用 tab-active 选中样式） -->
    <div class="flex items-center gap-1 bg-slate-100 rounded-xl p-1">
      <button id="cloudTab-tencent" class="cloud-tab px-3 py-1.5 rounded-lg text-sm font-medium transition">腾讯轻量云</button>
      <button id="cloudTab-huawei" class="cloud-tab px-3 py-1.5 rounded-lg text-sm font-medium transition">华为云</button>
    </div>
    <div class="flex items-center gap-2">
      <span id="credBadge" class="hidden text-xs px-2 py-1 rounded-lg"></span>
      <span id="authBadge" class="hidden text-xs px-2 py-1 rounded-lg"></span>
      <button id="btnCred" class="text-sm px-3 py-2 rounded-xl border border-blue-600 text-blue-600 hover:bg-blue-50 transition">密钥配置</button>
      <button id="btnLogout" class="text-sm px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100 transition">退出</button>
    </div>
  </div>
</header>

<!-- 主账号风险横幅 -->
<div id="riskBanner" class="hidden max-w-7xl mx-auto px-4 pt-4">
  <div class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 leading-relaxed">
    <b>⚠ 高危提示：</b>当前使用<b>腾讯云主账号密钥</b>调用 API，该密钥等同于账号最高权限（可销毁全部资源、购买资源、访问全部云产品与账单）。
    请务必：① 面板部署在 HTTPS 且仅本人访问；② 定期轮换密钥；③ 尽快在 CAM 创建仅授权轻量服务器的子账号密钥替换（改配置区 AUTH_MODE 为 sub）。
  </div>
</div>

<!-- 实例选择栏 -->
<div class="max-w-7xl mx-auto px-4 py-4">
  <div class="bg-white rounded-2xl shadow-sm p-4 flex flex-col md:flex-row md:items-center gap-3">
    <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs text-slate-500 mb-1">地域</label>
        <select id="selRegion" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500"></select>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">实例</label>
        <select id="selInstance" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500"></select>
      </div>
    </div>
    <div class="flex gap-2">
      <button id="btnRefresh" class="px-4 py-2 rounded-xl bg-slate-800 text-white text-sm hover:bg-slate-900 transition">刷新</button>
    </div>
  </div>
</div>

<!-- Tab -->
<div class="max-w-7xl mx-auto px-4">
  <div class="flex flex-wrap gap-2 mb-4">
    <button class="tab-btn tab-active px-4 py-2 rounded-xl text-sm bg-white border border-slate-200" data-tab="overview">实例概览</button>
    <button class="tab-btn px-4 py-2 rounded-xl text-sm bg-white border border-slate-200" data-tab="firewall">安全组 / 防火墙</button>
    <button class="tab-btn px-4 py-2 rounded-xl text-sm bg-white border border-slate-200" data-tab="snapshot">快照备份</button>
    <button class="tab-btn px-4 py-2 rounded-xl text-sm bg-white border border-slate-200" data-tab="monitor">监控</button>
    <button class="tab-btn px-4 py-2 rounded-xl text-sm bg-white border border-slate-200" data-tab="logs">操作日志</button>
    <button class="tab-btn px-4 py-2 rounded-xl text-sm bg-white border border-slate-200" data-tab="about">说明 / 风险</button>
  </div>
</div>

<main class="max-w-7xl mx-auto px-4 pb-16">

  <!-- ============ 概览 ============ -->
  <section id="tab-overview" class="tab-pane">
    <div id="insCard" class="bg-white rounded-2xl shadow-sm p-5"></div>
  </section>

  <!-- ============ 防火墙 ============ -->
  <section id="tab-firewall" class="tab-pane hidden">
    <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h2 class="font-bold text-slate-800">防火墙规则 <span id="fwInst" class="text-xs font-normal text-slate-400"></span></h2>
          <p class="text-xs text-slate-400 mt-1">轻量服务器防火墙为实例级入站规则，出站默认放行；规则变更实时生效。</p>
        </div>
        <button id="btnFwAdd" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm hover:bg-blue-700">+ 新增规则</button>
      </div>
      <div class="mb-4">
        <div class="text-xs text-slate-500 mb-2">快捷放行模板（TCP，来源 0.0.0.0/0）</div>
        <div class="flex flex-wrap gap-2" id="tplBox"></div>
      </div>
      <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs">
            <tr><th class="px-4 py-3 text-left">协议</th><th class="px-4 py-3 text-left">端口</th><th class="px-4 py-3 text-left">来源</th><th class="px-4 py-3 text-left">策略</th><th class="px-4 py-3 text-left">备注</th><th class="px-4 py-3 text-right">操作</th></tr>
          </thead>
          <tbody id="fwBody" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
      <div id="fwEmpty" class="hidden text-center text-sm text-slate-400 py-8">暂无规则</div>
    </div>
  </section>

  <!-- ============ 快照 ============ -->
  <section id="tab-snapshot" class="tab-pane hidden">
    <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h2 class="font-bold text-slate-800">快照备份 <span id="snapInst" class="text-xs font-normal text-slate-400"></span></h2>
          <p class="text-xs text-slate-400 mt-1">快照是磁盘某一时刻的完整副本，可用于回滚系统盘。创建期间实例可正常使用，但会占用快照配额。</p>
        </div>
        <div class="flex gap-2">
          <button id="btnSnapRefresh" class="px-4 py-2 rounded-xl bg-slate-800 text-white text-sm">刷新</button>
          <button id="btnSnapCreate" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm hover:bg-blue-700">+ 创建快照</button>
        </div>
      </div>
      <div id="snapNotice" class="hidden mb-3 rounded-xl bg-amber-50 text-amber-800 px-4 py-3 text-sm"></div>
      <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs">
            <tr>
              <th class="px-4 py-3 text-left">快照名称</th>
              <th class="px-4 py-3 text-left">快照 ID</th>
              <th class="px-4 py-3 text-left">磁盘</th>
              <th class="px-4 py-3 text-left">状态</th>
              <th class="px-4 py-3 text-left">创建时间</th>
              <th class="px-4 py-3 text-right">操作</th>
            </tr>
          </thead>
          <tbody id="snapBody" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
      <div id="snapEmpty" class="hidden text-center text-sm text-slate-400 py-8">暂无快照</div>
    </div>
  </section>

  <!-- ============ 监控 ============ -->
  <section id="tab-monitor" class="tab-pane hidden">
    <div class="bg-white rounded-2xl shadow-sm p-5">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="font-bold text-slate-800">资源监控 <span id="monInst" class="text-xs font-normal text-slate-400"></span></h2>
        <div class="flex gap-2">
          <button class="rng-btn px-3 py-1.5 text-sm rounded-xl bg-blue-600 text-white" data-range="24h">近 24 小时</button>
          <button class="rng-btn px-3 py-1.5 text-sm rounded-xl bg-white border border-slate-300" data-range="7d">近 7 天</button>
        </div>
      </div>
      <div id="monEmpty" class="hidden rounded-xl bg-amber-50 text-amber-700 text-sm px-4 py-3 mb-4"></div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-xl border border-slate-200 p-4"><div class="text-sm font-medium mb-2 text-slate-600">CPU 使用率（%）</div><div class="relative h-56"><canvas id="chart-cpu"></canvas></div></div>
        <div class="rounded-xl border border-slate-200 p-4"><div class="text-sm font-medium mb-2 text-slate-600">内存使用率（%）</div><div class="relative h-56"><canvas id="chart-mem"></canvas></div></div>
        <div class="rounded-xl border border-slate-200 p-4"><div class="text-sm font-medium mb-2 text-slate-600">公网出带宽（Mbps）</div><div class="relative h-56"><canvas id="chart-bwout"></canvas></div></div>
        <div class="rounded-xl border border-slate-200 p-4"><div class="text-sm font-medium mb-2 text-slate-600">公网入带宽（Mbps）</div><div class="relative h-56"><canvas id="chart-bwin"></canvas></div></div>
      </div>
      <p id="monNote" class="text-xs text-slate-400 mt-3">数据来源：腾讯云云监控 QCE/LIGHTHOUSE，带宽单位已由 MBytes/s 换算为 Mbps（×8）。</p>
    </div>
  </section>

  <!-- ============ 日志 ============ -->
  <section id="tab-logs" class="tab-pane hidden">
    <div class="bg-white rounded-2xl shadow-sm p-5">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h2 class="font-bold text-slate-800">操作日志</h2>
          <p class="text-xs text-slate-400 mt-1" id="logPath"></p>
        </div>
        <button id="btnLogRefresh" class="px-4 py-2 rounded-xl bg-slate-800 text-white text-sm">刷新</button>
      </div>
      <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs">
            <tr><th class="px-4 py-3 text-left">时间</th><th class="px-4 py-3 text-left">来源 IP</th><th class="px-4 py-3 text-left">操作</th><th class="px-4 py-3 text-left">对象</th><th class="px-4 py-3 text-left">结果</th><th class="px-4 py-3 text-left">说明</th></tr>
          </thead>
          <tbody id="logBody" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
      <div id="logEmpty" class="hidden text-center text-sm text-slate-400 py-8">暂无日志记录</div>
    </div>
  </section>

  <!-- ============ 关于 ============ -->
  <section id="tab-about" class="tab-pane hidden">
    <div class="bg-white rounded-2xl shadow-sm p-5 text-sm leading-relaxed text-slate-600 space-y-4">
      <div class="rounded-xl bg-blue-50 p-4">
        <h2 class="font-bold text-slate-800 mb-2">多云说明（腾讯 / 华为）</h2>
        <ul class="list-disc pl-5 space-y-1">
          <li>顶部可切换【腾讯轻量云 / 华为云】；两侧均支持：实例列表、开机、关机、重启、快照（创建 / 删除 / 回滚）、监控、重置密码、重装系统、安全组规则、操作日志。</li>
          <li>后端为适配器模式：TencentAdapter 复用原有 biz_* 逻辑；HuaweiAdapter 基于 HwV4Sign（SDK-HMAC-SHA256 V4）零依赖自实现，华为异步操作复用前端既有轮询。</li>
          <li>密钥仅存于浏览器 localStorage，后端不持久化；可在顶部「密钥配置」中填写并一键测试连通性。</li>
          <li>华为侧差异：安全组仅支持放行规则；重装系统（切换操作系统）需先关机；内存 / 网络监控需安装 CES Agent；快照针对系统盘（EVS 云快照）。</li>
        </ul>
      </div>
      <div>
        <h2 class="font-bold text-slate-800 mb-2">已实现的接口</h2>
        <ul class="list-disc pl-5 space-y-1">
          <li>DescribeInstances / DescribeInstancesTrafficPackages —— 实例列表与流量包</li>
          <li>StartInstances / StopInstances / RebootInstances —— 开机、关机、重启</li>
          <li>DescribeBlueprints + ResetInstance —— 拉取镜像、重装系统（带轮询状态）</li>
          <li>ResetInstancesPassword —— 重置管理员密码</li>
          <li>DescribeFirewallRules / CreateFirewallRules / DeleteFirewallRules —— 防火墙规则管理</li>
          <li>GetMonitorData（QCE/LIGHTHOUSE）—— CPU、内存、出入带宽</li>
          <li>ModifyInstanceName —— 实例改名</li>
          <li>DescribeRegions —— 地域列表</li>
        </ul>
      </div>
      <div class="rounded-xl bg-slate-50 p-4">
        <h2 class="font-bold text-slate-800 mb-2">安全与风险</h2>
        <ol class="list-decimal pl-5 space-y-1">
          <li>密钥仅存于服务端 PHP 配置区，前端 HTML / JS / 接口返回中均不含密钥；全部请求经后端代理。</li>
          <li>面板独立密码 + Session（超时自动登出）+ CSRF Token；连续 5 次密码错误锁定 5 分钟。</li>
          <li>重装系统、重置密码、关机、删除防火墙规则均需红色强提示二次确认。</li>
          <li>所有操作写入本地日志文件（时间、IP、操作类型、实例、结果）。</li>
          <li><b class="text-red-600">强烈建议切换为子账号密钥</b>：CAM 新建子用户 → 仅授予 QcloudLighthouseFullAccess（按需可改只读）→ 生成密钥 → 替换配置区密钥并把 AUTH_MODE 改为 sub。一旦主账号密钥泄露，整个腾讯云账号将完全失控。</li>
        </ol>
      </div>
      <div class="rounded-xl bg-slate-50 p-4">
        <h2 class="font-bold text-slate-800 mb-2">已知平台限制</h2>
        <ul class="list-disc pl-5 space-y-1">
          <li>轻量服务器防火墙 API 只有一组规则（实例级入站方向），无独立出站规则接口，出站默认全放行。</li>
          <li>本机运行面板时若提示“签名过期”，请检查服务器系统时间（宝塔 → 时间同步 / NTP）。</li>
          <li>重装 / 重置密码后需要几分钟生效；Windows 实例建议重装时一并设置新密码。</li>
        </ul>
      </div>
      <div class="rounded-xl bg-slate-50 p-4">
        <h2 class="font-bold text-slate-800 mb-2">本机环境自检</h2>
        <ul class="list-disc pl-5 space-y-1" id="envCheck"></ul>
      </div>
    </div>
  </section>
</main>

<!-- 弹窗 -->
<div id="mask" class="mask hidden">
  <div class="mask-box fade-in">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
      <h3 id="mkTitle" class="font-bold text-slate-800"></h3>
      <button onclick="closeMask()" class="text-slate-400 hover:text-slate-700 text-xl leading-none">×</button>
    </div>
    <div id="mkBody" class="px-5 py-4 text-sm"></div>
    <div class="px-5 py-3 border-t border-slate-100 flex justify-end gap-2">
      <button onclick="closeMask()" class="px-4 py-2 rounded-xl border border-slate-300 text-sm">取消</button>
      <button id="mkOk" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm">确定</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="fixed left-1/2 -translate-x-1/2 bottom-8 z-[60] hidden">
  <div id="toastBox" class="px-5 py-3 rounded-2xl text-sm shadow-lg bg-slate-900 text-white"></div>
</div>

<script>
window.__CFG__ = __CFG_JSON__;
const CFG = window.__CFG__;
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));

/* ---------- 基础 UI ---------- */
let toastTimer=null;
function toast(msg,type){
  const t=$('#toast'),b=$('#toastBox');
  b.textContent=msg;
  b.className='px-5 py-3 rounded-2xl text-sm shadow-lg '+(type==='error'?'bg-red-600':(type==='success'?'bg-green-600':'bg-slate-900'))+' text-white';
  t.classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer=setTimeout(()=>t.classList.add('hidden'),3200);
}
function openMask(title,bodyHtml,okText,onOk,danger){
  $('#mkTitle').textContent=title;
  $('#mkBody').innerHTML=bodyHtml;
  const ok=$('#mkOk');
  ok.textContent=okText||'确定';
  ok.className='px-4 py-2 rounded-xl text-sm text-white '+(danger?'bg-red-600 hover:bg-red-700':'bg-blue-600 hover:bg-blue-700');
  ok.onclick=()=>{ if(onOk) onOk(); };
  $('#mask').classList.remove('hidden');
}
function closeMask(){ $('#mask').classList.add('hidden'); $('#mkBody').innerHTML=''; $('#mkOk').onclick=null; }
/* 按需求修改：弹窗不再「点击遮罩自动关闭」——鼠标选中文字拖出弹窗松开时会误关。
   现在只能通过右上角 × / 取消 / 保存 按钮关闭弹窗。 */

/* ---------- API ---------- */
async function api(action,data){
  const body=Object.assign({action:action,csrf:CFG.csrf,cloud:S.cloud,cred:curCred()},data||{});
  let r;
  try{
    r=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  }catch(e){ throw new Error('网络请求失败：'+e.message); }
  let j;
  try{ j=await r.json(); }catch(e){ throw new Error('服务端返回异常（HTTP '+r.status+'），请检查 PHP 报错或伪静态配置'); }
  if(j.needLogin){ location.reload(); throw new Error('登录已失效'); }
  if(!j.ok) throw new Error(j.msg||'操作失败');
  return j.data||{};
}

/* ---------- 状态 ---------- */
const S={
  snapshots: [], region:CFG.region, instances:[], current:'', blueprints:{}, charts:{}, timer:null };

/* ---------- 初始化 ---------- */
/* ---------- 多云：云切换状态 + 密钥（仅前端 localStorage，后端不持久化） ---------- */
S.cloud = localStorage.getItem('lhpanel.cloud.v1')==='huawei' ? 'huawei' : 'tencent';
function loadCred(){
  let c={};
  try{ c=JSON.parse(localStorage.getItem('lhpanel.cred.v1')||'{}'); }catch(e){}
  c.tencent=c.tencent||{ak:'',sk:''};
  c.huawei=c.huawei||{ak:'',sk:'',project:''};
  return c;
}
S.cred = loadCred();
function saveCred(){ localStorage.setItem('lhpanel.cred.v1', JSON.stringify(S.cred)); }
function curCred(){
  const c=S.cred[S.cloud]||{};
  return {ak:c.ak||'', sk:c.sk||'', project:c.project||''};
}
/* 顶部云切换按钮样式（沿用 tab-active）+ 风险横幅 / 密钥徽标（腾讯侧沿用原有逻辑） */
function applyCloudUI(){
  $$('.cloud-tab').forEach(b=>{
    const on = b.id==='cloudTab-'+S.cloud;
    b.classList.toggle('tab-active',on);
    if(on){ b.classList.add('bg-blue-600','text-white'); b.classList.remove('bg-white','text-slate-600'); }
    else  { b.classList.remove('bg-blue-600','text-white'); b.classList.add('bg-white','text-slate-600'); }
  });
  const b=$('#authBadge');
  if(S.cloud!=='tencent'){
    // 华为侧密钥来自前端配置，不展示主/子账号徽标
    $('#riskBanner').classList.add('hidden');
    b.classList.add('hidden');
  }else if(CFG.authMode==='main'){
    $('#riskBanner').classList.remove('hidden');
    b.className='text-xs px-2 py-1 rounded-lg bg-red-100 text-red-700 font-medium';
    b.textContent='主账号密钥';
    b.classList.remove('hidden');
  }else{
    $('#riskBanner').classList.remove('hidden');
    b.className='text-xs px-2 py-1 rounded-lg bg-green-100 text-green-700 font-medium';
    b.textContent='子账号密钥';
    b.classList.remove('hidden');
  }
}
/* 应用后端下发的密码规则（各云要求不同） */
function applyPwdRule(r){
  if(!r) return;
  CFG.pwdMin=r.min||CFG.pwdMin; CFG.pwdMax=r.max||CFG.pwdMax;
  CFG.pwdDesc=r.desc||CFG.pwdDesc;
  if(r.strict!==undefined) CFG.pwdStrict=r.strict?1:0;
}
/* 服务端（index.php 配置区）已配置密钥时显示绿色徽标，前端「密钥配置」可留空 */
function applyBackendCredBadge(on){
  const b=$('#credBadge');
  if(on){ b.className='text-xs px-2 py-1 rounded-lg bg-green-100 text-green-700 font-medium'; b.textContent='服务端已配置密钥'; b.classList.remove('hidden'); }
  else b.classList.add('hidden');
  S.backendCred=!!on;
}

(async function init(){
  if(typeof tailwind==='undefined'){
    document.body.insertAdjacentHTML('afterbegin','<div class="bg-amber-50 text-amber-800 text-sm px-4 py-2 text-center">Tailwind CSS CDN 未加载成功，页面样式可能不完整（不影响功能）。可在配置区更换 CDN 地址。</div>');
  }
  applyCloudUI();
  // 云厂商切换：重新拉取地域与实例（沿用原有加载逻辑）
  $$('.cloud-tab').forEach(b=>b.addEventListener('click',async()=>{
    const target=b.id.replace('cloudTab-','');
    if(S.cloud===target) return;
    S.cloud=target; localStorage.setItem('lhpanel.cloud.v1',S.cloud);
    applyCloudUI();
    try{
      const boot=await api('bootstrap');
      S.regions=boot.regions;
      fillRegions(boot.regions,boot.region||CFG.region);
      applyPwdRule(boot.pwdRule);
      applyBackendCredBadge(!!boot.backendCred);
      await loadInstances(true);
      toast('已切换到'+(S.cloud==='huawei'?'华为云':'腾讯轻量云'),'success');
    }catch(e){ toast(e.message,'error'); }
  }));
  $('#logPath').textContent='日志文件：'+CFG.logFile+(CFG.logWritable?'':'（⚠ 当前不可写，请检查目录权限）');
  renderEnvCheck();
  bindTabs();
  bindEvents();
  try{
    const boot=await api('bootstrap');
    S.regions=boot.regions;
    fillRegions(boot.regions,boot.region||CFG.region);
    applyPwdRule(boot.pwdRule);
    applyBackendCredBadge(!!boot.backendCred);
    await loadInstances(true);
  }catch(e){ toast(e.message,'error'); }
})();

/* ---------- 密钥配置（仅存浏览器 localStorage，含连通性测试） ---------- */
function openCredForm(){
  const isHw=S.cloud==='huawei';
  const c=S.cred[S.cloud]||{};
  openMask('云密钥配置 · '+(isHw?'华为云':'腾讯轻量云'),`
    <div class="rounded-xl bg-blue-50 text-blue-800 px-4 py-3 text-sm leading-relaxed">
      密钥仅保存在<b>本浏览器 localStorage</b>，随请求传给后端临时转发；后端<b>不落盘、不写日志、不存数据库</b>，清除浏览器数据即可删除密钥。
      ${S.backendCred?'<div class="mt-2 text-xs text-green-700">✓ 服务端 index.php 已配置密钥：此处可全部留空直接使用；若填写则以这里的密钥优先。</div>':''}
    </div>
    <div class="mt-3 space-y-3">
      <div>
        <label class="block text-xs text-slate-500 mb-1">AK（${isHw?'Access Key':'SecretId'}）</label>
        <input id="cAk" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" value="${esc(c.ak||'')}" placeholder="${isHw?'华为云 Access Key':'AKID...'}">
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">SK（${isHw?'Secret Access Key':'SecretKey'}）</label>
        <input id="cSk" type="password" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" value="${esc(c.sk||'')}" placeholder="请输入 SK">
      </div>
      ${isHw?`
      <div>
        <label class="block text-xs text-slate-500 mb-1">ProjectId（IAM 项目 ID，必填）</label>
        <input id="cProj" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" value="${esc(c.project||'')}" placeholder="例如：0a3c8a9b...">
        <div class="text-xs text-slate-400 mt-1">控制台右上角「我的凭证 → 项目列表」，取所选地域对应的项目 ID。</div>
      <div class="text-xs text-amber-600 mt-1">提示：密钥如已停用 / 删除 / 重新生成过，需以「访问密钥」页面当前状态为「启用」的密钥为准重新填写。</div>
      </div>`:''}
      <div class="text-xs text-slate-400">提示：保存后立即生效；建议使用仅授权 ECS / Lighthouse 的子账号密钥。</div>
    </div>`,'保存',async()=>{
      S.cred[S.cloud]={ak:$('#cAk').value.trim(),sk:$('#cSk').value.trim(),project:isHw?$('#cProj').value.trim():''};
      saveCred(); closeMask(); toast('密钥已保存到本浏览器','success');
    });
  $('#mkBody').insertAdjacentHTML('beforeend','<button id="btnTestConn" class="mt-3 w-full px-4 py-2 rounded-xl border border-blue-600 text-blue-600 text-sm hover:bg-blue-50 transition">测试连通性</button>');
  $('#btnTestConn').addEventListener('click',async()=>{
    // 先暂存当前输入再测试（无需先点保存）
    S.cred[S.cloud]={ak:$('#cAk').value.trim(),sk:$('#cSk').value.trim(),project:isHw?$('#cProj').value.trim():''};
    saveCred();
    const btn=$('#btnTestConn'); btn.disabled=true; btn.textContent='测试中...';
    try{
      const d=await api('test_conn',{region:S.region});
      btn.textContent='✓ 连通成功';
      toast(d.msg||'连通成功','success');
    }catch(e){
      btn.disabled=false; btn.textContent='测试连通性';
      toast('连通失败：'+e.message,'error');
    }
  });
  // 华为云：填写 ProjectId 后自动反查归属地域并选中（免手动切换地域）
  if(isHw){
    let projTimer=null;
    $('#cProj').addEventListener('input',()=>{
      clearTimeout(projTimer);
      projTimer=setTimeout(async()=>{
        const pid=$('#cProj').value.trim(), ak=$('#cAk').value.trim(), sk=$('#cSk').value.trim();
        if(pid.length<20||!ak||!sk) return;
        try{
          const d=await api('hw_project',{projectId:pid,cred:{ak:ak,sk:sk,project:pid}});
          const el=$('#selRegion');
          if(!Array.from(el.options).some(o=>o.value===d.region)){
            el.insertAdjacentHTML('beforeend','<option value="'+esc(d.region)+'">'+esc(d.region+' · '+(d.displayName||d.region))+'</option>');
          }
          el.value=d.region; S.region=el.value;
          toast('已自动定位到地域：'+(d.displayName||d.region),'success');
        }catch(e){ /* 反查失败（如子账号无 IAM 查询权限）时保持手动选择 */ }
      },900);
    });
  }
}

function renderEnvCheck(){
  const items=[];
  items.push((typeof tailwind!=='undefined'?'✔':'✘')+' Tailwind CSS 样式库');
  items.push((typeof Chart!=='undefined'?'✔':'✘（监控图将使用内置简易绘图）')+' Chart.js 图表库');
  items.push((CFG.logWritable?'✔':'✘（请给网站目录写权限）')+' 日志文件可写');
  $('#envCheck').innerHTML=items.map(x=>'<li>'+x+'</li>').join('');
}

function fillRegions(regions,sel){
  const el=$('#selRegion');
  el.innerHTML=regions.map(r=>'<option value="'+r.region+'">'+r.region+' · '+r.name+'</option>').join('');
  el.value=sel||regions[0]?.region||'';
  S.region=el.value;
}

function bindTabs(){
  $$('.tab-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
      $$('.tab-btn').forEach(b=>b.classList.remove('tab-active','bg-blue-600','text-white'));
      btn.classList.add('tab-active','bg-blue-600','text-white');
      const name=btn.dataset.tab;
      $$('.tab-pane').forEach(p=>p.classList.add('hidden'));
      $('#tab-'+name).classList.remove('hidden');
      if(name==='firewall') loadFw();
      if(name==='snapshot') loadSnapshots();
      if(name==='monitor') loadMonitor();
      if(name==='logs') loadLogs();
    });
  });
}

function bindEvents(){
  $('#selRegion').addEventListener('change',async()=>{ S.region=$('#selRegion').value; await loadInstances(true); });
  $('#selInstance').addEventListener('change',()=>{ S.current=$('#selInstance').value; renderInstance(); });
  $('#btnRefresh').addEventListener('click',async()=>{ await loadInstances(false); toast('已刷新','success'); });
  $('#btnLogout').addEventListener('click',async()=>{ await api('logout'); location.reload(); });
  $('#btnCred').addEventListener('click',openCredForm);

  $$('.rng-btn').forEach(b=>{
    b.addEventListener('click',()=>{
      $$('.rng-btn').forEach(x=>{x.className='rng-btn px-3 py-1.5 text-sm rounded-xl bg-white border border-slate-300';});
      b.className='rng-btn px-3 py-1.5 text-sm rounded-xl bg-blue-600 text-white';
      loadMonitor(b.dataset.range);
    });
  });
  $('#btnLogRefresh').addEventListener('click',loadLogs);

  const tpls=[
    {p:'22',d:'SSH 远程登录'},{p:'80',d:'HTTP 网站'},{p:'443',d:'HTTPS 网站'},
    {p:'3389',d:'Windows 远程桌面'},{p:'888',d:'宝塔面板'},{p:'3306',d:'MySQL 数据库'}
  ];
  $('#tplBox').innerHTML=tpls.map(t=>'<button data-port="'+t.p+'" class="tpl-btn px-3 py-1.5 text-sm rounded-xl bg-slate-100 hover:bg-blue-50 hover:text-blue-700 border border-slate-200">放行 '+t.p+'（'+t.d+'）</button>').join('');
  $$('.tpl-btn').forEach(b=>b.addEventListener('click',()=>quickAdd(b.dataset.port)));
  $('#btnFwAdd').addEventListener('click',()=>openFwForm());
  $('#btnSnapRefresh').addEventListener('click',()=>loadSnapshots());
  $('#btnSnapCreate').addEventListener('click',openSnapCreate);
}

/* ---------- 实例 ---------- */
async function loadInstances(reset){
  try{
    const d=await api('instances',{region:S.region});
    S.instances=d.instances||[];
    const el=$('#selInstance');
    const prev=reset?(S.instances[0]?.id||''):(S.current||S.instances[0]?.id||'');
    el.innerHTML=S.instances.length? S.instances.map(i=>'<option value="'+i.id+'">'+esc(i.name||i.id)+' · '+esc(i.public[0]||'无公网IP')+'</option>').join('')
      :'<option value="">该地域下没有实例</option>';
    if(S.instances.length){
      el.value=S.instances.some(i=>i.id===prev)?prev:S.instances[0].id;
      S.current=el.value;
    }else{ S.current=''; }
    renderInstance();
  }catch(e){
    $('#insCard').innerHTML='<div class="rounded-xl bg-red-50 text-red-700 text-sm px-4 py-3">'+esc(e.message)+'</div>';
    throw e;
  }
}
function cur(){ return S.instances.find(i=>i.id===S.current)||null; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function renderInstance(){
  const i=cur(), box=$('#insCard');
  if(!i){ box.innerHTML='<div class="text-sm text-slate-400">请先在上方选择地域与实例。</div>'; return; }
  const used=i.traffic?i.traffic.used:0, total=i.traffic?i.traffic.total:0, rem=i.traffic?i.traffic.remaining:0;
  const pct=total>0?Math.min(100,Math.round(used/total*100)):0;
  const over=i.traffic&&i.traffic.overflow>0;
  const badge={green:'bg-green-100 text-green-700',red:'bg-red-100 text-red-700',amber:'bg-amber-100 text-amber-700',slate:'bg-slate-100 text-slate-600'}[i.stateColor||'slate'];
  box.innerHTML=`
  <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
    <div class="min-w-0">
      <div class="flex items-center gap-2 flex-wrap">
        <h2 class="text-lg font-bold truncate">${esc(i.name||i.id)}</h2>
        <span class="text-xs px-2 py-1 rounded-lg ${badge}">${esc(i.stateCn)}</span>
        <button id="btnRename" class="text-xs text-blue-600 hover:underline">改名 / 备注</button>
      </div>
      <div class="text-xs text-slate-400 mt-1">${esc(i.id)} · ${esc(S.region)} ${esc(i.zone?('· '+i.zone):'')}</div>
    </div>
    <div class="flex flex-wrap gap-2">
      <button class="op-btn px-3 py-2 text-sm rounded-xl ${i.state==='STOPPED'?'bg-green-600 text-white':'bg-slate-200 text-slate-400 cursor-not-allowed'}" data-op="start" ${i.state==='STOPPED'?'':'disabled title="仅已关机的实例可以开机"'}>开机</button>
      <button class="op-btn px-3 py-2 text-sm rounded-xl ${i.state==='RUNNING'?'bg-amber-500 text-white':'bg-slate-200 text-slate-400 cursor-not-allowed'}" data-op="stop" ${i.state==='RUNNING'?'':'disabled title="仅运行中的实例可以关机"'}>关机</button>
      <button class="op-btn px-3 py-2 text-sm rounded-xl ${i.state==='RUNNING'?'bg-slate-700 text-white':'bg-slate-200 text-slate-400 cursor-not-allowed'}" data-op="reboot" ${i.state==='RUNNING'?'':'disabled title="仅运行中的实例可以重启"'}>重启</button>
      <button class="px-3 py-2 text-sm rounded-xl bg-blue-600 text-white" id="btnPwd">重置密码</button>
      <button class="px-3 py-2 text-sm rounded-xl bg-red-600 text-white" id="btnReinstall">重装系统</button>
    </div>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    ${kv('公网 IP',(i.public||[]).join('<br>')||'-')}
    ${kv('内网 IP',(i.private||[]).join('<br>')||'-')}
    ${kv('IPv6',(i.ipv6||[]).join('<br>')||'未分配')}
    ${kv('操作系统',i.os||'-')}
  </div>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    ${kv('套餐规格',(i.cpu?i.cpu+' 核 / '+i.mem+' GB 内存':'')+(i.bandwidth?(' · '+i.bandwidth+' Mbps 峰值带宽'):'')+(i.disk?(' · 系统盘 '+i.disk+' GB'):''))}
    ${kv('套餐 ID',i.bundle||'-')}
    ${kv('镜像 ID',i.blueprint||'-')}
    ${kv('到期时间',i.expired?(fmtTime(i.expired)+(i.charge==='PREPAID'?'（包年包月）':'')):'-')}
  </div>

  ${i.traffic?`
  <div class="rounded-xl border border-slate-200 p-4">
    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
      <div class="text-sm font-medium text-slate-700">流量包（本月）</div>
      <div class="text-xs text-slate-400">${i.traffic&&i.traffic.deadline?('重置时间：'+esc(fmtTime(i.traffic.deadline))):''}</div>
    </div>
    <div class="h-3 rounded-full bg-slate-100 overflow-hidden">
      <div class="h-full ${pct>80?'bg-red-500':(pct>50?'bg-amber-500':'bg-blue-500')}" style="width:${pct}%"></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3 text-sm">
      ${kv('已使用',i.traffic?fmtBytes(used):'-')}
      ${kv('剩余流量',i.traffic?fmtBytes(rem):'-')}
      ${kv('总流量',i.traffic?fmtBytes(total):'-')}
      ${kv('超额流量',i.traffic?(over?fmtBytes(i.traffic.overflow):'无'):'-')}
    </div>
    ${over?'<div class="mt-3 text-xs text-red-600">⚠ 流量已超额，实例公网带宽可能受限。</div>':''}
  </div>`:''}`;

  $$('#insCard .op-btn').forEach(b=>b.addEventListener('click',()=>doPower(b.dataset.op,i)));
  $('#btnPwd').addEventListener('click',()=>openPwdForm(i));
  $('#btnReinstall').addEventListener('click',()=>openReinstall(i));
  $('#btnRename').addEventListener('click',()=>openRename(i));
}
function kv(k,v){ return '<div class="rounded-xl bg-slate-50 px-3 py-2"><div class="text-xs text-slate-400">'+k+'</div><div class="text-sm text-slate-700 mt-0.5 break-all">'+(v||'-')+'</div></div>'; }
function fmtTime(s){
  if(!s) return '-';
  return String(s).replace('T',' ').replace('Z','').replace(/\+08:00$/,'');
}
function fmtBytes(b){
  b=Number(b||0); const u=['B','KB','MB','GB','TB','PB']; let x=0;
  while(b>=1024&&x<5){b/=1024;x++;}
  return (x===0?Math.round(b):b.toFixed(2))+' '+u[x];
}

/* ---------- 开关机重启 ---------- */
function doPower(op,i){
  const st=i.state, stCn=i.stateCn||st;
  if(op==='start'&&st!=='STOPPED'){ toast(st==='RUNNING'?'实例已在运行中，无需开机':'实例当前为「'+stCn+'」，请等操作完成后再试','error'); return; }
  if((op==='stop'||op==='reboot')&&st!=='RUNNING'){ toast('实例当前为「'+stCn+'」，仅运行中的实例可以'+(op==='stop'?'关机':'重启'),'error'); return; }
  const map={start:['开机','实例将进入运行中状态。'],stop:['关机','⚠ 关机期间实例上所有服务将不可用，计费仍然继续（轻量包年包月）。'],reboot:['重启','⚠ 实例将重启，期间服务短暂中断。']};
  const t=map[op];
  openMask('确认'+t[0],`
    <div class="space-y-3">
      <div>实例：<b>${esc(i.name||i.id)}</b>（${esc(i.id)}）</div>
      <div class="rounded-xl bg-amber-50 text-amber-800 px-4 py-3">${t[1]}</div>
    </div>`,'确认'+t[0],async()=>{
    closeMask();
    try{ const d=await api('power',{region:S.region,instanceId:i.id,op:op}); toast(d.msg,'success'); setTimeout(()=>loadInstances(false),1500); }
    catch(e){ toast(e.message,'error'); }
  },op!=='start');
}

/* ---------- 重置密码 ---------- */
function openPwdForm(i){
  const isWin=/windows/i.test(i.os||'');
  openMask('重置实例管理员密码',`
    <div class="space-y-3">
      <div class="rounded-xl bg-red-50 text-red-700 px-4 py-3">
        ⚠ 高危操作：重置后<b>旧密码立即失效</b>，所有已保存该密码的客户端、脚本、服务需同步更新。
      </div>
      <div class="text-sm text-slate-500">实例：${esc(i.name||i.id)}（${esc(i.id)}）${isWin?' · Windows':' · Linux'}</div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">用户名（可留空，默认 ${isWin?'Administrator':'root'}）</label>
        <input id="pUser" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="${isWin?'Administrator':'root'}">
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">新密码</label>
        <input id="pPwd" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="请输入新密码">
        <div class="text-xs text-slate-400 mt-1">规则：${esc(CFG.pwdDesc)}</div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">确认新密码</label>
        <input id="pPwd2" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="再次输入">
      </div>
      <label class="flex items-start gap-2 text-sm text-slate-700">
        <input id="pChk" type="checkbox" class="mt-1"> <span>我已了解：重置后旧密码失效，并已保存好新密码。</span>
      </label>
    </div>`,'确认重置',async()=>{
      const u=$('#pUser').value.trim(), p=$('#pPwd').value, p2=$('#pPwd2').value, chk=$('#pChk').checked;
      if(!chk){ toast('请先勾选确认框','error'); return; }
      if(p!==p2){ toast('两次输入的密码不一致','error'); return; }
      if(!checkPwd(p)) return;
      closeMask();
      try{ const d=await api('reset_pwd',{region:S.region,instanceId:i.id,password:p,username:u}); toast(d.msg,'success'); }
      catch(e){ toast(e.message,'error'); }
    },true);
}
function checkPwd(p){
  if(p.length<CFG.pwdMin||p.length>CFG.pwdMax){ toast('密码长度需为 '+CFG.pwdMin+'~'+CFG.pwdMax+' 位','error'); return false; }
  if(CFG.pwdStrict){
    let k=0; if(/[A-Z]/.test(p))k++; if(/[a-z]/.test(p))k++; if(/[0-9]/.test(p))k++; if(/[^A-Za-z0-9]/.test(p))k++;
    if(k<3){ toast('密码需包含大写字母、小写字母、数字、特殊符号中的至少三类','error'); return false; }
    if(/\s/.test(p)){ toast('密码不能包含空格','error'); return false; }
  }else{
    if(!(/[A-Za-z]/.test(p)&&/[0-9]/.test(p))){ toast('密码需同时包含字母和数字','error'); return false; }
  }
  return true;
}

/* ---------- 重装系统 ---------- */
async function openReinstall(i){
  openMask('重装系统',`
    <div class="rounded-xl bg-red-50 text-red-700 px-4 py-3 mb-3">
      ⚠⚠ <b>极高危操作</b>：重装将<b>清空系统盘全部数据</b>（网站、数据库、配置文件、脚本等），且<b>无法恢复</b>。
      数据盘不受影响（若有）。请务必先做好快照或备份。
    </div>
    <div class="text-sm text-slate-500 mb-3">实例：${esc(i.name||i.id)}（${esc(i.id)}）</div>
    ${S.cloud==='huawei'?'<div class="rounded-xl bg-amber-50 text-amber-800 px-4 py-2 mb-3 text-sm">华为云说明：重装（切换操作系统）为异步任务，要求实例处于<b>已关机</b>状态，请先关机再重装。</div>':''}
    <div class="mb-3">
      <label class="block text-xs text-slate-500 mb-1">选择镜像（实时拉取 ${esc(S.region)} 可用镜像）</label>
      <select id="rBp" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="">加载中...</option></select>
    </div>
    <div class="mb-3">
      <label class="block text-xs text-slate-500 mb-1">同时设置新密码（可选，留空则沿用原登录方式；Windows 建议填写）</label>
      <input id="rPwd" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="留空表示不修改密码">
    </div>
    <label class="flex items-start gap-2 text-sm text-red-700 font-medium">
      <input id="rChk" type="checkbox" class="mt-1"> <span>我确认已备份数据，并接受系统盘数据被全部清空且无法恢复。</span>
    </label>`,'确认重装',async()=>{
      const bp=$('#rBp').value, pwd=$('#rPwd').value, chk=$('#rChk').checked;
      if(!bp){ toast('请选择镜像','error'); return; }
      if(!chk){ toast('请先勾选“确认已备份”','error'); return; }
      if(pwd && !checkPwd(pwd)) return;
      closeMask();
      const bpid=bp;
      openMask('再次确认',`
        <div class="rounded-xl bg-red-100 text-red-700 px-4 py-3 mb-3">
          最后一次确认：即将对实例 <b>${esc(i.id)}</b> 执行重装，系统盘数据将被全部清空。
        </div>
        <div class="text-sm text-slate-600">请输入实例 ID 的后 6 位以继续：<b class="text-slate-800">${esc(i.id.slice(-6))}</b></div>
        <input id="rConfirm" class="w-full mt-2 rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="输入后 6 位">`,
        '我确认，开始重装',async()=>{
          if($('#rConfirm').value.trim()!==i.id.slice(-6)){ toast('输入不匹配，已取消','error'); return; }
          closeMask();
          try{
            const d=await api('reset_system',{region:S.region,instanceId:i.id,blueprintId:bpid,password:pwd});
            toast(d.msg,'success');
            pollReinstall(i.id);
          }catch(e){ toast(e.message,'error'); }
        },true);
    },true);

  // 拉取镜像列表
  try{
    const d=await api('blueprints',{region:S.region,instanceId:i.id});
    let list=d.blueprints||[];
    // 是否可重装完全以腾讯云 DescribeResetInstanceBlueprints 的 IsResettable 为准（后端已滤掉不可用的），
    // 前端不再按当前实例平台二次过滤——否则实例当前是 Linux 时会把 Windows 镜像全部误杀
    const curBp=i.blueprint||'';
    const groups={};
    list.forEach(b=>{ (groups[b.typeCn]=groups[b.typeCn]||[]).push(b); });
    let html='';
    Object.keys(groups).forEach(g=>{
      html+='<optgroup label="'+esc(g)+'">';
      groups[g].forEach(b=>{
        const mark=(b.id===curBp)?'（当前）':'';
        html+='<option value="'+esc(b.id)+'">'+esc(b.title+(b.version?(' '+b.version):'')+(b.os?(' · '+b.os):''))+esc(mark)+'</option>';
      });
      html+='</optgroup>';
    });
    $('#rBp').innerHTML=html||'<option value="">该地域无可用镜像</option>';
  }catch(e){
    $('#rBp').innerHTML='<option value="">镜像列表获取失败：'+esc(e.message)+'</option>';
  }
}

function pollReinstall(id){
  let n=0;
  const tip=toast('重装任务已下发，正在等待完成...','info');
  clearInterval(S.timer);
  S.timer=setInterval(async()=>{
    n++;
    try{
      const d=await api('instances',{region:S.region});
      const it=(d.instances||[]).find(x=>x.id===id);
      if(it){
        S.instances=d.instances; renderInstance();
        if(it.state==='RUNNING'){ clearInterval(S.timer); toast('重装完成，实例已恢复运行中','success'); }
      }
    }catch(e){}
    if(n>=60){ clearInterval(S.timer); toast('轮询结束，请手动刷新查看状态','info'); }
  },10000);
}

/* ---------- 改名 ---------- */
function openRename(i){
  openMask('修改实例名称',`
    <div class="space-y-3">
      <div class="text-sm text-slate-500">实例 ID：${esc(i.id)}</div>
      <input id="nName" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" value="${esc(i.name)}" placeholder="新的实例名称">
      <div class="text-xs text-slate-400">最多 60 个字符</div>
    </div>`,'保存',async()=>{
      const v=$('#nName').value.trim();
      if(!v){ toast('名称不能为空','error'); return; }
      closeMask();
      try{ const d=await api('rename',{region:S.region,instanceId:i.id,name:v}); toast(d.msg,'success'); await loadInstances(false); }
      catch(e){ toast(e.message,'error'); }
    });
}

/* ---------- 防火墙 ---------- */
async function loadFw(){
  const i=cur();
  $('#fwInst').textContent = i ? ('（'+ (i.name||i.id) +' · '+ i.id +'）') : '';
  if(!i){ $('#fwBody').innerHTML=''; return; }
  try{
    const d=await api('fw_list',{region:S.region,instanceId:i.id});
    renderFw(d.rules||[]);
  }catch(e){ toast(e.message,'error'); $('#fwBody').innerHTML=''; }
}
function renderFw(rules){
  const tb=$('#fwBody');
  if(!rules.length){ tb.innerHTML=''; $('#fwEmpty').classList.remove('hidden'); return; }
  $('#fwEmpty').classList.add('hidden');
  tb.innerHTML=rules.map((r,idx)=>`
    <tr class="hover:bg-slate-50">
      <td class="px-4 py-3">${esc(r.protocol)}</td>
      <td class="px-4 py-3">${esc(r.port||'ALL')}</td>
      <td class="px-4 py-3">${esc(r.cidr)}</td>
      <td class="px-4 py-3"><span class="text-xs px-2 py-1 rounded-lg ${r.action==='ACCEPT'?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${r.action==='ACCEPT'?'放行':'拒绝'}</span></td>
      <td class="px-4 py-3 text-slate-500">${esc(r.desc||'-')}</td>
      <td class="px-4 py-3 text-right"><button data-idx="${idx}" class="del-btn text-xs px-3 py-1.5 rounded-lg bg-red-50 text-red-600 hover:bg-red-100">删除</button></td>
    </tr>`).join('');
  $$('#fwBody .del-btn').forEach(b=>b.addEventListener('click',()=>{
    const r=rules[+b.dataset.idx];
    openMask('删除防火墙规则',`
      <div class="rounded-xl bg-red-50 text-red-700 px-4 py-3 mb-3">
        ⚠ 高危操作：删除后对应端口将<b>立即不可访问</b>，可能导致网站 / SSH 失联。
      </div>
      <div class="text-sm space-y-1">
        <div>协议：<b>${esc(r.protocol)}</b></div>
        <div>端口：<b>${esc(r.port||'ALL')}</b></div>
        <div>来源：<b>${esc(r.cidr)}</b></div>
        <div>策略：<b>${esc(r.action)}</b></div>
      </div>`,'确认删除',async()=>{
        closeMask();
        try{ const d=await api('fw_del',{region:S.region,instanceId:cur().id,rule:r}); toast(d.msg,'success'); loadFw(); }
        catch(e){ toast(e.message,'error'); }
      },true);
  }));
}
function openFwForm(){
  openMask('新增防火墙规则',`
    <div class="space-y-3">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-500 mb-1">协议</label>
          <select id="fProto" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <option value="TCP">TCP</option><option value="UDP">UDP</option>
            <option value="ICMP">ICMP</option><option value="ICMPv6">ICMPv6</option><option value="ALL">ALL</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-500 mb-1">策略</label>
          <select id="fAction" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <option value="ACCEPT">放行（ACCEPT）</option><option value="DROP">拒绝（DROP）</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">端口（80 / 80,443 / 8000-8080 / ALL）</label>
        <input id="fPort" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="80">
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">来源 IP / CIDR</label>
        <input id="fCidr" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" value="0.0.0.0/0" placeholder="0.0.0.0/0">
        <div class="text-xs text-slate-400 mt-1">0.0.0.0/0 表示对所有 IPv4 地址生效。建议 SSH / 数据库等敏感端口仅放行自己的 IP。</div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">备注（≤64 字符）</label>
        <input id="fDesc" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="例如：放行 Web 服务">
      </div>
      <div class="text-xs text-slate-400">说明：轻量防火墙为入站方向规则，出站默认全部放行。</div>
      ${S.cloud==='huawei'?'<div class="text-xs text-amber-600 mt-1">华为云：安全组为入站「放行」规则（不支持拒绝策略与备注），将添加到实例绑定的第一个安全组。</div>':''}
    </div>`,'添加规则',async()=>{
      const rule={protocol:$('#fProto').value,port:$('#fPort').value.trim(),cidr:$('#fCidr').value.trim(),action:$('#fAction').value,desc:$('#fDesc').value.trim()};
      if(S.cloud==='huawei'){
        if(rule.action==='DROP'){ toast('华为云安全组仅支持放行（ACCEPT）规则','error'); return; }
        if(rule.port.indexOf(',')>=0){ toast('华为云安全组不支持逗号分隔多端口，请使用单端口或范围（如 8000-8080）','error'); return; }
      }
      closeMask();
      try{ const d=await api('fw_add',{region:S.region,instanceId:cur().id,rule:rule}); toast(d.msg,'success'); loadFw(); }
      catch(e){ toast(e.message,'error'); }
    });
}
async function quickAdd(port){
  const i=cur(); if(!i) return;
  try{
    const d=await api('fw_add',{region:S.region,instanceId:i.id,rule:{protocol:'TCP',port:port,cidr:'0.0.0.0/0',action:'ACCEPT',desc:'面板快捷放行 '+port}});
    toast(d.msg,'success'); loadFw();
  }catch(e){ toast(e.message,'error'); }
}

/* ---------- 监控 ---------- */
/* ---------- 快照 ---------- */
async function loadSnapshots(){
  const i=cur();
  $('#snapInst').textContent = i ? ('（'+ (i.name||i.id) +' · '+ i.id +'）') : '';
  const nb=$('#snapNotice'); nb.classList.add('hidden');
  if(!i){ $('#snapBody').innerHTML=''; return; }
  try{
    const d=await api('snap_list',{region:S.region,instanceId:i.id});
    S.snapshots=d.snapshots||[];
    renderSnap(S.snapshots);
    // 有创建中/回滚中的快照则自动轮询
    if(S.snapshots.some(x=>x.state==='CREATING'||x.state==='ROLLBACKING')) pollSnap();
  }catch(e){ toast(e.message,'error'); $('#snapBody').innerHTML=''; }
}

function renderSnap(list){
  const tb=$('#snapBody');
  if(!list.length){
    tb.innerHTML='';
    $('#snapEmpty').classList.remove('hidden');
    $('#snapEmpty').textContent='暂无快照，点右上角「+ 创建快照」生成第一个备份';
    return;
  }
  $('#snapEmpty').classList.add('hidden');
  tb.innerHTML=list.map((x,idx)=>{
    const busy=(x.state==='CREATING'||x.state==='ROLLBACKING'||x.state==='DELETING');
    const ok=(x.state==='NORMAL');
    const badge = ok?'bg-green-100 text-green-700':(busy?'bg-amber-100 text-amber-700':'bg-slate-100 text-slate-600');
    const pct = busy?('<div class="text-[11px] text-slate-400 mt-1">'+x.percent+'%</div>'):'';
    const dis = busy?'disabled title="快照正在处理中，暂不可操作"':'';
    return `
    <tr class="hover:bg-slate-50">
      <td class="px-4 py-3 font-medium text-slate-700 break-all">${esc(x.name||'-')}</td>
      <td class="px-4 py-3 text-xs text-slate-500 font-mono">${esc(x.id)}</td>
      <td class="px-4 py-3 text-slate-600">${esc(x.diskUsageCn||'-')} · ${x.size||0} GB</td>
      <td class="px-4 py-3"><span class="text-xs px-2 py-1 rounded-lg ${badge}">${esc(x.stateCn)}</span>${pct}</td>
      <td class="px-4 py-3 text-slate-500 whitespace-nowrap">${esc(fmtTime(x.created))}</td>
      <td class="px-4 py-3 text-right whitespace-nowrap">
        <button data-act="apply" data-idx="${idx}" ${ok?'':'disabled'} class="snap-btn text-xs px-3 py-1.5 rounded-lg ${ok?'bg-blue-50 text-blue-600 hover:bg-blue-100':'bg-slate-100 text-slate-400 cursor-not-allowed'}">回滚</button>
        <button data-act="del" data-idx="${idx}" ${dis} class="snap-btn text-xs px-3 py-1.5 rounded-lg ${busy?'bg-slate-100 text-slate-400 cursor-not-allowed':'bg-red-50 text-red-600 hover:bg-red-100'}">删除</button>
      </td>
    </tr>`;
  }).join('');

  $$('#snapBody .snap-btn').forEach(b=>{
    b.addEventListener('click',()=>{
      const x=list[+b.dataset.idx];
      if(b.dataset.act==='del') askDeleteSnap(x);
      else askApplySnap(x);
    });
  });
}

function askDeleteSnap(x){
  openMask('删除快照',`
    <div class="rounded-xl bg-red-50 text-red-700 px-4 py-3 mb-3">
      ⚠ 删除后<b>无法恢复</b>，该时间点的备份将永久消失。
    </div>
    <div class="text-sm space-y-1">
      <div>名称：<b>${esc(x.name)}</b></div>
      <div>ID：<b class="font-mono">${esc(x.id)}</b></div>
      <div>磁盘：${esc(x.diskUsageCn)} · ${x.size} GB</div>
      <div>创建时间：${esc(fmtTime(x.created))}</div>
    </div>`,'确认删除',async()=>{
    closeMask();
    try{ const d=await api('snap_del',{region:S.region,snapshotIds:[x.id]}); toast(d.msg,'success'); loadSnapshots(); }
    catch(e){ toast(e.message,'error'); }
  },true);
}

function askApplySnap(x){
  openMask('回滚到快照',`
    <div class="rounded-xl bg-red-50 text-red-700 px-4 py-3 mb-3">
      ⚠ <b>极高危操作</b>：回滚会把系统盘恢复到该快照时刻，<b>此后的所有数据变更全部丢失</b>，实例会重启。
    </div>
    <div class="text-sm space-y-1">
      <div>目标快照：<b>${esc(x.name)}</b>（<span class="font-mono">${esc(x.id)}</span>）</div>
      <div>创建时间：${esc(fmtTime(x.created))}</div>
      <div class="text-slate-500 mt-2">建议回滚前先对当前状态再创建一个快照作为保险。</div>
    </div>`,'确认回滚',async()=>{
    closeMask();
    try{ const d=await api('snap_apply',{region:S.region,instanceId:cur().id,snapshotId:x.id}); toast(d.msg,'success'); loadSnapshots(); }
    catch(e){ toast(e.message,'error'); }
  },true);
}

function openSnapCreate(){
  const i=cur();
  if(!i){ toast('请先选择实例','error'); return; }
  const def='snap-'+new Date().toISOString().slice(0,19).replace(/[-:T]/g,'').slice(0,15);
  openMask('创建快照',`
    <div class="space-y-3">
      <div class="text-sm text-slate-500">实例：<b>${esc(i.name||i.id)}</b>（${esc(i.id)}）</div>
      <div class="rounded-xl bg-blue-50 text-blue-800 px-4 py-3 text-sm">
        快照会完整备份<b>系统盘</b>当前状态，创建期间实例可正常使用。耗时取决于磁盘大小，通常几分钟。
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">快照名称（可留空自动生成）</label>
        <input id="sName" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" value="${esc(def)}">
      </div>
    </div>`,'开始创建',async()=>{
    const nm=($('#sName')?$('#sName').value.trim():'');
    closeMask();
    try{ const d=await api('snap_create',{region:S.region,instanceId:i.id,name:nm}); toast(d.msg,'success'); loadSnapshots(); }
    catch(e){ toast(e.message,'error'); }
  },false);
}

let snapTimer=null;
function pollSnap(){
  clearTimeout(snapTimer);
  snapTimer=setTimeout(async()=>{
    if(!S.current) return;
    try{
      const d=await api('snap_list',{region:S.region,instanceId:S.current});
      S.snapshots=d.snapshots||[]; renderSnap(S.snapshots);
      if(S.snapshots.some(x=>x.state==='CREATING'||x.state==='ROLLBACKING')) pollSnap();
      else toast('快照任务已结束','success');
    }catch(e){ /* 静默 */ }
  },8000);
}

async function loadMonitor(range){
  const i=cur(); if(!i) return;
  $('#monInst').textContent = '（'+ (i.name||i.id) +' · '+ i.id +'）';
  range=range||'24h';
  const keys=['cpu','mem','bwout','bwin'];
  keys.forEach(k=>{ if(S.charts[k]){S.charts[k].destroy();S.charts[k]=null;} });
  try{
    const d=await api('monitor',{region:S.region,instanceId:i.id,range:range});
    if(d.empty){ $('#monEmpty').textContent=d.note||'未获取到监控数据'; $('#monEmpty').classList.remove('hidden'); }
    else $('#monEmpty').classList.add('hidden');
    $('#monNote').textContent = S.cloud==='huawei'
      ? '数据来源：华为云云监控（CES）SYS.ECS 命名空间；内存 / 网络指标需实例安装 CES Agent 上报，带宽由 bit/s 换算为 Mbps。'
      : '数据来源：腾讯云云监控 QCE/LIGHTHOUSE，带宽单位已由 MBytes/s 换算为 Mbps（×8）。';
    keys.forEach(k=>drawChart(k,(d.series||{})[k]||{points:[],unit:'',name:''}));
  }catch(e){ toast(e.message,'error'); }
}
function drawChart(key,ser){
  const c=document.getElementById('chart-'+key);
  if(!c) return;
  const pts=ser.points||[];
  if(!pts.length){ drawFallback(c,'无数据'); return; }
  if(typeof Chart==='undefined'){ drawFallbackCanvas(c,pts,ser.unit); return; }
  // 双保险：loadMonitor 已统一销毁，这里再按 Chart.js 注册表兜底，
  // 防止两次并发 loadMonitor（await 期间被再次触发）导致 Canvas already in use
  const existed=Chart.getChart(c); if(existed){ existed.destroy(); }
  S.charts[key]=new Chart(c,{
    type:'line',
    data:{labels:pts.map(p=>fmtTs(p[0],ser.points.length>200)),
          datasets:[{label:ser.name+'（'+ser.unit+'）',data:pts.map(p=>p[1]),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.12)',fill:true,tension:.25,pointRadius:0,pointHoverRadius:4,borderWidth:2}]},
    options:{
      responsive:true,maintainAspectRatio:false,
      interaction:{mode:'index',intersect:false},
      scales:{
        x:{type:'category',grid:{display:false},ticks:{maxTicksLimit:8,color:'#94a3b8',font:{size:10},autoSkip:true}},
        y:{beginAtZero:true,grid:{color:'#f1f5f9'},ticks:{color:'#94a3b8',font:{size:10}}}
      },
      plugins:{
        legend:{display:false},
        tooltip:{backgroundColor:'rgba(15,23,42,.92)',padding:10,displayColors:false,
          callbacks:{title:(it)=>it[0].label,
            label:(it)=>ser.name+'：'+(Math.round(it.parsed.y*1000)/1000)+' '+ser.unit}}
      }
    }
  });
}
function p2(n){ return n<10?('0'+n):(''+n); }
function fmtTs(ms,dense){ const d=new Date(ms); return dense ? (p2(d.getMonth()+1)+'-'+p2(d.getDate())+' '+p2(d.getHours())+':'+p2(d.getMinutes())) : (p2(d.getHours())+':'+p2(d.getMinutes())); }
/* Chart.js CDN 不可用时的简易降级绘图 */
function drawFallback(canvas,msg){
  const ctx=canvas.getContext('2d'); const r=window.devicePixelRatio||1;
  canvas.width=canvas.clientWidth*r; canvas.height=canvas.clientHeight*r;
  ctx.setTransform(r,0,0,r,0,0); ctx.clearRect(0,0,canvas.clientWidth,canvas.clientHeight);
  ctx.fillStyle='#94a3b8'; ctx.font='13px sans-serif'; ctx.fillText(msg,10,20);
}
function drawFallbackCanvas(canvas,pts,unit){
  const ctx=canvas.getContext('2d'); const r=window.devicePixelRatio||1;
  const W=canvas.clientWidth||300,H=canvas.clientHeight||200;
  canvas.width=W*r; canvas.height=H*r; ctx.setTransform(r,0,0,r,0,0);
  ctx.clearRect(0,0,W,H);
  const pad={l:38,r:8,t:10,b:22};
  let max=0; pts.forEach(p=>{ if(p[1]>max) max=p[1]; });
  if(max<=0) max=1;
  const xs=i=>pad.l+(W-pad.l-pad.r)*(i/(pts.length-1||1));
  const ys=v=>H-pad.b-(H-pad.t-pad.b)*(v/(max*1.1));
  ctx.strokeStyle='#e2e8f0'; ctx.fillStyle='#94a3b8'; ctx.font='10px sans-serif'; ctx.lineWidth=1;
  for(let i=0;i<=4;i++){ const y=H-pad.b-(H-pad.t-pad.b)*(i/4);
    ctx.beginPath(); ctx.moveTo(pad.l,y); ctx.lineTo(W-pad.r,y); ctx.stroke();
    ctx.fillText((max*1.1*i/4).toFixed(unit==='%'?0:2),4,y+3); }
  ctx.beginPath(); ctx.strokeStyle='#2563eb'; ctx.lineWidth=2;
  pts.forEach((p,i)=>{ i?ctx.lineTo(xs(i),ys(p[1])):ctx.moveTo(xs(i),ys(p[1])); });
  ctx.stroke();
  ctx.lineTo(xs(pts.length-1),H-pad.b); ctx.lineTo(xs(0),H-pad.b); ctx.closePath();
  ctx.fillStyle='rgba(37,99,235,.1)'; ctx.fill();
  canvas.onmousemove=ev=>{
    const rect=canvas.getBoundingClientRect(); const mx=ev.clientX-rect.left;
    let best=0,bd=1e9;
    pts.forEach((p,i)=>{ const d=Math.abs(xs(i)-mx); if(d<bd){bd=d;best=i;} });
    ctx.clearRect(0,0,W,H);
    drawFallbackCanvasSilent(canvas,pts,unit,max,xs,ys,pad,W,H);
    ctx.fillStyle='#0f172a';
    const d=new Date(pts[best][0]);
    const txt=p2(d.getMonth()+1)+'-'+p2(d.getDate())+' '+p2(d.getHours())+':'+p2(d.getMinutes())+' · '+(Math.round(pts[best][1]*100)/100)+' '+unit;
    ctx.fillText(txt,Math.min(xs(best)+8,W-150),pad.t+10);
  };
  canvas.onmouseleave=()=>drawFallbackCanvas(canvas,pts,unit);
}
function drawFallbackCanvasSilent(canvas,pts,unit,max,xs,ys,pad,W,H){
  const ctx=canvas.getContext('2d');
  ctx.clearRect(0,0,W,H);
  ctx.strokeStyle='#e2e8f0'; ctx.fillStyle='#94a3b8'; ctx.font='10px sans-serif'; ctx.lineWidth=1;
  for(let i=0;i<=4;i++){ const y=H-pad.b-(H-pad.t-pad.b)*(i/4);
    ctx.beginPath(); ctx.moveTo(pad.l,y); ctx.lineTo(W-pad.r,y); ctx.stroke();
    ctx.fillText((max*1.1*i/4).toFixed(unit==='%'?0:2),4,y+3); }
  ctx.beginPath(); ctx.strokeStyle='#2563eb'; ctx.lineWidth=2;
  pts.forEach((p,i)=>{ i?ctx.lineTo(xs(i),ys(p[1])):ctx.moveTo(xs(i),ys(p[1])); });
  ctx.stroke();
  ctx.lineTo(xs(pts.length-1),H-pad.b); ctx.lineTo(xs(0),H-pad.b); ctx.closePath();
  ctx.fillStyle='rgba(37,99,235,.1)'; ctx.fill();
}

/* ---------- 日志 ---------- */
async function loadLogs(){
  try{
    const d=await api('log_list');
    const rows=d.logs||[];
    if(!rows.length){ $('#logBody').innerHTML=''; $('#logEmpty').classList.remove('hidden'); return; }
    $('#logEmpty').classList.add('hidden');
    $('#logBody').innerHTML=rows.map(r=>`
      <tr class="hover:bg-slate-50">
        <td class="px-4 py-2 whitespace-nowrap">${esc(r.time)}</td>
        <td class="px-4 py-2">${esc(r.ip)}</td>
        <td class="px-4 py-2">${esc(r.action)}</td>
        <td class="px-4 py-2">${esc(r.target)}</td>
        <td class="px-4 py-2"><span class="text-xs px-2 py-1 rounded-lg ${r.result==='success'?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${r.result==='success'?'成功':'失败'}</span></td>
        <td class="px-4 py-2 text-slate-500">${esc(r.message)}</td>
      </tr>`).join('');
  }catch(e){ toast(e.message,'error'); }
}
</script>
</body>
</html>
HTML;

    $html = str_replace('__CDN_TAILWIND__', h(CDN_TAILWIND), $html);
    $html = str_replace('__CDN_CHARTJS__', h(CDN_CHARTJS), $html);
    $html = str_replace('__CFG_JSON__', $cfgJson, $html);
    echo $html;
}
