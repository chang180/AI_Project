<?php
declare(strict_types=1);

/**
 * ToyCrypto — 教學示意密碼學工具箱（玩具版，非安全！）
 * ====================================================
 * ⚠️ 嚴正聲明：本檔所有「加密」皆為「教學示意」，**絕對不能用於真實場景**。
 *   - DH 用「小質數」做模冪運算（真實要用 2048-bit 以上的大數 / Curve25519）。
 *   - 對稱加密用 XOR（真實要用 AES-GCM 等帶驗證的對稱加密）。
 *   - 環境限制：禁用 openssl_* / gmp_*，僅能用 PHP 整數與 hash() 示意。
 * 但以下「概念」是真的、與 Signal Protocol 一致：
 *   - DH 雙方各自用私鑰算出「相同的共享祕密」，竊聽者只看到公鑰也算不出。
 *   - 用 KDF（這裡用 hash 當金鑰導出函式）把共享祕密導成對稱金鑰。
 *   - 棘輪（ratchet）：每發一則就把金鑰用 hash 單向推進一次 → 前向保密。
 *
 * 為什麼能用 hash 做 KDF？
 *   hash 是「單向」的：給輸入算得出輸出，但給輸出反推不回輸入。
 *   這正是 KDF 與棘輪推進需要的性質（HKDF 內部就是以 HMAC 為基礎）。
 */
final class ToyCrypto
{
    /**
     * 玩具版 Diffie-Hellman 的公開參數（小質數，純示意）。
     *   p = 大質數（這裡用很小的 467 示意），g = 原根（生成元）。
     *   真實系統 p 是 2048-bit 以上的安全質數，或改用橢圓曲線 Curve25519。
     */
    public const DH_P = 467;   // 模數（小質數，示意用）
    public const DH_G = 2;     // 生成元

    /**
     * 產生 DH 私鑰：1 < a < p-1 的隨機整數。
     * 真實系統私鑰是 256-bit 以上的隨機數。
     */
    public static function dhPrivateKey(): int
    {
        // random_int 屬語言核心（非 openssl），可用
        return random_int(2, self::DH_P - 2);
    }

    /**
     * 由私鑰算公鑰：  A = g^a mod p  （模冪運算）。
     * 公鑰可以公開傳遞；竊聽者就算拿到 A 也很難反推私鑰 a（離散對數難題）。
     */
    public static function dhPublicKey(int $priv): int
    {
        return self::modpow(self::DH_G, $priv, self::DH_P);
    }

    /**
     * 算共享祕密：  s = (對方公鑰)^自己私鑰 mod p 。
     * 神奇之處：  (g^b)^a mod p  ==  (g^a)^b mod p  → 雙方算出「相同的 s」，
     *           但中間人只看到公鑰 A、B，算不出 s（這就是金鑰交換的精髓）。
     */
    public static function dhSharedSecret(int $peerPublic, int $ownPrivate): int
    {
        return self::modpow($peerPublic, $ownPrivate, self::DH_P);
    }

    /**
     * KDF（金鑰導出函式）：把 DH 共享祕密 + 用途標籤，導出一把對稱「根金鑰」。
     * 這裡用 hash() 示意 HKDF。回傳 64 字元的 hex 金鑰字串。
     */
    public static function kdf(int $sharedSecret, string $info = 'root'): string
    {
        return hash('sha256', 'KDF|' . $info . '|' . $sharedSecret);
    }

    /**
     * 棘輪推進（ratchet step）：把目前的鏈金鑰用 hash 單向推進到「下一把」。
     *   newKey = H( "ratchet" | oldKey )
     * 單向性保證：拿到 newKey **算不出** oldKey（前向保密的數學基礎）。
     * 每發一則訊息就推進一次 → 每則訊息用不同的金鑰。
     */
    public static function ratchet(string $chainKey): string
    {
        return hash('sha256', 'ratchet|' . $chainKey);
    }

    /**
     * 由鏈金鑰導出「這一則訊息」的訊息金鑰（與鏈金鑰分離，再單向一次）。
     *   messageKey = H( "msg" | chainKey )
     */
    public static function messageKey(string $chainKey): string
    {
        return hash('sha256', 'msg|' . $chainKey);
    }

    /**
     * 對稱加密（教學示意）：用訊息金鑰對明文做 XOR。
     *   - 把 hex 金鑰當成 keystream，循環與明文位元組 XOR。
     *   - 真實系統用 AES-GCM（帶完整性驗證）。XOR 無認證、可被竄改，僅供示意。
     * 回傳 hex 密文（避免二進位在 JSON / 終端機出問題）。
     */
    public static function encrypt(string $plaintext, string $messageKey): string
    {
        return bin2hex(self::xorStream($plaintext, $messageKey));
    }

    /** 對稱解密：與加密相同（XOR 自反），輸入 hex 密文回傳明文。 */
    public static function decrypt(string $cipherHex, string $messageKey): string
    {
        $cipher = self::hexToBin($cipherHex);
        return self::xorStream($cipher, $messageKey);
    }

    // ---------------- 底層工具 ----------------

    /**
     * 模冪運算  base^exp mod mod  ——「平方-相乘」法，純整數，不需 gmp。
     * 因為用的是小質數（p=467），中間值不會溢位 PHP 整數。
     * 真實大數會溢位 → 才需要 gmp/bcmath/openssl，本 demo 刻意用小數字示意。
     */
    public static function modpow(int $base, int $exp, int $mod): int
    {
        $result = 1;
        $base %= $mod;
        while ($exp > 0) {
            if (($exp & 1) === 1) {
                $result = ($result * $base) % $mod;
            }
            $exp >>= 1;
            $base = ($base * $base) % $mod;
        }
        return $result;
    }

    /** keystream XOR：把 hex 金鑰當位元組循環，與資料逐位元組 XOR。 */
    private static function xorStream(string $data, string $key): string
    {
        $kbytes = self::hexToBin($key);
        $klen = strlen($kbytes);
        if ($klen === 0) {
            return $data;
        }
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($data[$i]) ^ ord($kbytes[$i % $klen]));
        }
        return $out;
    }

    /** hex → 原始位元組（不依賴 mbstring）。 */
    private static function hexToBin(string $hex): string
    {
        $bin = @hex2bin($hex);
        return $bin === false ? '' : $bin;
    }
}
