<?php

namespace QPlayer;

/**
 * 网易云音乐 Cookie 保活器
 *
 * MUSIC_U 有有效期，过期后云盘/会员资源全部失效且只能手动重新抓取。
 * 这里在每次网易云请求前按天限频调用官方续期接口 login/token/refresh，
 * 拿到 Set-Cookie 里的新 MUSIC_U 后写回插件配置（options 表）持久化，
 * 只要博客保持有访问，cookie 就会一直自动续期不再过期。
 */
class CookieKeeper
{
    /* 续期间隔：24 小时，避免每个播放请求都打一次网易接口 */
    const REFRESH_INTERVAL = 86400;

    const REFRESH_URL = 'https://music.163.com/weapi/login/token/refresh';

    /**
     * 入口：按需续期，返回当前应使用的 cookie
     *
     * @param \Typecho_Config $config 插件配置
     * @return string
     */
    public static function keepAlive($config)
    {
        $cookie = trim((string) $config->cookie);
        if ($cookie === '' || stripos($cookie, 'MUSIC_U') === false) {
            return $cookie;
        }
        $last = (int) $config->cookieRefreshedAt;
        if (time() - $last < self::REFRESH_INTERVAL) {
            return $cookie;
        }
        /* 先落时间戳：即使接口异常也不会让后续每个请求都重复尝试刷新 */
        \Helper::configPlugin('QPlayer2', array('cookieRefreshedAt' => time()));

        $newCookie = self::refresh($cookie);
        if ($newCookie !== null && $newCookie !== $cookie) {
            \Helper::configPlugin('QPlayer2', array('cookie' => $newCookie));
            return $newCookie;
        }
        return $cookie;
    }

    /**
     * 调用网易云续期接口，成功则返回合并了新 MUSIC_U/__csrf 的完整 cookie
     *
     * @param string $cookie
     * @return string|null 失败返回 null
     */
    private static function refresh($cookie)
    {
        $body = array();
        /* weapi 惯例：带上 __csrf 对应的 csrf_token */
        if (preg_match('/__csrf=([^;\s]+)/', $cookie, $m)) {
            $body['csrf_token'] = $m[1];
        }
        $payload = self::weapiEncrypt($body);
        if ($payload === null) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::REFRESH_URL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        /* 带响应头：新 cookie 在 Set-Cookie 里 */
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Referer: https://music.163.com/',
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: os=pc; ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
        ));
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status !== 200) {
            return null;
        }

        $split = strrpos($raw, "\r\n\r\n");
        $headers = $split === false ? $raw : substr($raw, 0, $split);
        $json = $split === false ? '' : substr($raw, $split + 4);
        $res = json_decode($json, true);
        /* code 非 200 说明 cookie 已彻底失效，续不动了，保留原值等待人工更换 */
        if (!is_array($res) || (int) (isset($res['code']) ? $res['code'] : 0) !== 200) {
            return null;
        }

        /* 解析 Set-Cookie 并合并进原 cookie（只关心会变化的键） */
        preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/mi', $headers, $matches, PREG_SET_ORDER);
        $updated = $cookie;
        foreach ($matches as $set) {
            $name = trim($set[1]);
            $value = trim($set[2]);
            if (!in_array($name, array('MUSIC_U', '__csrf')) || $value === '') {
                continue;
            }
            if (preg_match('/(^|;\s*)' . preg_quote($name, '/') . '=/', $updated)) {
                $updated = preg_replace('/(^|;\s*)' . preg_quote($name, '/') . '=[^;]*/', '$1' . $name . '=' . $value, $updated);
            } else {
                $updated .= '; ' . $name . '=' . $value;
            }
        }
        return $updated;
    }

    /**
     * weapi 参数加密（逻辑取自 Meting::netease_AESCBC，其为 private 无法复用）
     *
     * @param array $body
     * @return array|null
     */
    private static function weapiEncrypt($body)
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }
        $modulus = '157794750267131502212476817800345498121872783333389747424011531025366277535262539913701806290766479189477533597854989606803194253978660329941980786072432806427833685472618792592200595694346872951301770580765135349259590167490536138082469680638514416594216629258349130257685001248172188325316586707301643237607';
        $pubkey = '65537';
        $nonce = '0CoJUm6Qyw8W8jud';
        $vi = '0102030405060708';

        if (extension_loaded('bcmath') && function_exists('random_bytes')) {
            $skey = bin2hex(random_bytes(8));
        } else {
            $skey = 'B3v3kH4vRPWRJFfH';
        }

        $data = json_encode((object) $body);
        $data = openssl_encrypt($data, 'aes-128-cbc', $nonce, false, $vi);
        $data = openssl_encrypt($data, 'aes-128-cbc', $skey, false, $vi);

        if (extension_loaded('bcmath') && $skey !== 'B3v3kH4vRPWRJFfH') {
            $enc = strrev($skey);
            $enc = self::bchexdec(self::str2hex($enc));
            $enc = bcpowmod($enc, $pubkey, $modulus);
            $enc = self::bcdechex($enc);
            $enc = str_pad($enc, 256, '0', STR_PAD_LEFT);
        } else {
            $enc = '85302b818aea19b68db899c25dac229412d9bba9b3fcfe4f714dc016bc1686fc446a08844b1f8327fd9cb623cc189be00c5a365ac835e93d4858ee66f43fdc59e32aaed3ef24f0675d70172ef688d376a4807228c55583fe5bac647d10ecef15220feef61477c28cae8406f6f9896ed329d6db9f88757e31848a6c2ce2f94308';
        }

        return array(
            'params'    => $data,
            'encSecKey' => $enc,
        );
    }

    private static function bchexdec($hex)
    {
        $dec = 0;
        $len = strlen($hex);
        for ($i = 1; $i <= $len; $i++) {
            $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
        }
        return $dec;
    }

    private static function bcdechex($dec)
    {
        $hex = '';
        do {
            $last = bcmod($dec, 16);
            $hex = dechex($last) . $hex;
            $dec = bcdiv(bcsub($dec, $last), 16);
        } while ($dec > 0);
        return $hex;
    }

    private static function str2hex($string)
    {
        $hex = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $hex .= substr('0' . dechex(ord($string[$i])), -2);
        }
        return $hex;
    }
}
