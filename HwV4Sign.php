<?php
/**
 * ============================================================================
 *  华为云 API V4 签名工具类（SDK-HMAC-SHA256）—— 零依赖，可直接 include 复用
 * ----------------------------------------------------------------------------
 *  适用：PHP >= 7.4（推荐 8.x）。本实现与面板 index.php 内联版本保持一致。
 *
 *  华为云 API 网关签名规范（与 AWS SigV4 的区别：签名密钥不做逐级派生，
 *  直接用 SK 对 StringToSign 做一次 HMAC-SHA256）：
 *
 *    CanonicalRequest =
 *        HTTPRequestMethod + '\n' + CanonicalURI + '\n' + CanonicalQueryString
 *      + '\n' + CanonicalHeaders + '\n' + SignedHeaders + '\n'
 *      + HexEncode(Hash(RequestPayload))
 *
 *    StringToSign = 'SDK-HMAC-SHA256' + '\n' + X-Sdk-Date + '\n'
 *                 + HexEncode(Hash(CanonicalRequest))
 *
 *    Signature     = HexEncode(HMAC-SHA256(StringToSign, SK))
 *    Authorization = 'SDK-HMAC-SHA256 Access={AK}, SignedHeaders=..., Signature=...'
 *    （与官方 SDK AKSKSigner.java 一致：使用 Access={AK}，不是 AWS 风格的
 *      Credential={AK}/{时间戳}；Access 后面不带时间戳）
 *    CanonicalURI（签名用）= 每段编码后末尾补「/」，如 /v1/{proj}/servers/detail/
 *    —— 官方规范「计算签名时 URI 必须以 / 结尾」；实际请求 URL 不带尾斜杠
 *
 *  要点：
 *   - 参与签名的头固定为 host 与 x-sdk-date（key 全小写、字典序、值 trim）
 *   - X-Sdk-Date 为 ISO8601 基本格式 UTC 时间：yyyymmddTHHMMSSZ
 *   - URI 与查询串按 RFC3986 严格编码（空格 → %20，~ 不编码）
 *   - 请求头只需额外携带 Host / X-Sdk-Date / Authorization（Content-Type 不参与签名）
 * ============================================================================
 */
declare(strict_types=1);

final class HwV4Sign
{
    /**
     * 构造签名请求头
     *
     * @param string $ak      IAM Access Key
     * @param string $sk      IAM Secret Access Key
     * @param string $method  HTTP 方法：GET / POST / PUT / DELETE / PATCH
     * @param string $host    主机名，如 ecs.cn-north-4.myhuaweicloud.com
     * @param string $path    请求路径，如 /v1/{project_id}/cloudservers/detail（可含未编码片段）
     * @param array  $query   查询参数（key => value，标量即可）
     * @param string $payload 请求体原文（POST/PUT 的 JSON 字符串；GET 传空串）
     * @return array{Host:string, X-Sdk-Date:string, Authorization:string}
     *         直接附加到 HTTP 请求头即可
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

        // 参与签名的头：host + x-sdk-date（key 全小写并排序，值两侧去空格）
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

        // CanonicalRequest
        $canonicalRequest = strtoupper($method) . "\n"
            . self::canonicalUri($path) . "\n"
            . self::canonicalQuery($query) . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . hash('sha256', $payload);

        // StringToSign（算法名 + 时间戳 + 哈希后的 CanonicalRequest）
        $stringToSign = 'SDK-HMAC-SHA256' . "\n" . $ts . "\n" . hash('sha256', $canonicalRequest);

        // 签名：单次 HMAC-SHA256（不做 AWS SigV4 式的密钥逐级派生）
        $signature = hash_hmac('sha256', $stringToSign, $sk);

        return [
            'Host'          => $host,
            'X-Sdk-Date'    => $ts,
            // 官方格式：Access={AK}（无时间戳后缀），见华为云《API签名指南》示例
            'Authorization' => 'SDK-HMAC-SHA256'
                . ' Access=' . $ak
                . ', SignedHeaders=' . $signedHeaders
                . ', Signature=' . $signature,
        ];
    }

    /** CanonicalURI（签名用）：按段编码后每段末尾补「/」（官方规范：签名时 URI 必须以 / 结尾） */
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

    /** CanonicalQueryString：按 key、value 字典序排序后编码，& 连接（空查询返回空串） */
    public static function canonicalQuery(array $q): string
    {
        if (!$q) return '';
        $pairs = [];
        foreach ($q as $k => $v) {
            $pairs[] = [self::enc((string)$k), self::enc((string)$v)];
        }
        usort($pairs, function ($a, $b) {
            return $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]);
        });
        return implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $pairs));
    }

    /** RFC3986 严格编码（~ 不编码，空格为 %20） */
    public static function enc(string $s): string
    {
        return str_replace('%7E', '~', rawurlencode($s));
    }
}

/* ============================================================================
 *  使用示例（curl 调用华为云 ECS 查询实例列表）
 * ----------------------------------------------------------------------------
 *  $host = 'ecs.cn-north-4.myhuaweicloud.com';
 *  $path = '/v1/{project_id}/cloudservers/detail';
 *  $sign = HwV4Sign::signHeaders($ak, $sk, 'GET', $host, $path, ['limit' => 100, 'offset' => 1]);
 *  $ch = curl_init('https://' . $host . HwV4Sign::canonicalUri($path) . '?' . HwV4Sign::canonicalQuery(['limit' => 100, 'offset' => 1]));
 *  curl_setopt_array($ch, [
 *      CURLOPT_RETURNTRANSFER => true,
 *      CURLOPT_HTTPHEADER => [
 *          'Authorization: ' . $sign['Authorization'],
 *          'X-Sdk-Date: '    . $sign['X-Sdk-Date'],
 *          'Host: '          . $sign['Host'],
 *      ],
 *  ]);
 *  $resp = curl_exec($ch);
 * ============================================================================
 */
