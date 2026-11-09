<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Optional password for the interface: a stateless signed cookie, valid for 30 days.
 * The signing key combines the password with a random secret kept in the data folder
 * (session.key), so a captured cookie does not help to guess the password. Changing the
 * password or deleting session.key logs everybody out.
 */
final class Session
{
    public const COOKIE = 'labeler_session';
    public const LIFETIME = 30 * 24 * 3600;

    private string $password;
    private string $dir;
    private ?string $secret = null;

    /**
     * @param string $password plain text or a hash from PHP's password_hash()
     * @param string $dir data folder for session.key; without one, the key comes from the password alone
     */
    public function __construct(string $password, string $dir = '')
    {
        $this->password = $password;
        $this->dir = $dir;
    }

    public function enabled(): bool
    {
        return $this->password !== '';
    }

    public function verify(string $given): bool
    {
        if (!$this->enabled() || $given === '') {
            return false;
        }
        if (preg_match('/^\$(2y|argon2i|argon2id)\$/', $this->password)) {
            return password_verify($given, $this->password);
        }
        return hash_equals($this->password, $given);
    }

    public function issue(int $now): string
    {
        $expires = (string) ($now + self::LIFETIME);
        return $expires . '.' . $this->sign($expires);
    }

    public function valid(?string $cookie, int $now): bool
    {
        if (!$this->enabled() || $cookie === null || !preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $cookie, $m)) {
            return false;
        }
        return (int) $m[1] > $now && hash_equals($this->sign($m[1]), $m[2]);
    }

    public static function cookie(string $value, bool $secure, int $maxAge): string
    {
        return self::COOKIE . '=' . $value . '; Path=/; Max-Age=' . $maxAge . '; HttpOnly; SameSite=Strict' . ($secure ? '; Secure' : '');
    }

    private function sign(string $expires): string
    {
        return hash_hmac('sha256', $expires, hash('sha256', 'labeler-session|' . $this->password . '|' . $this->secret()));
    }

    /** The random secret in the data folder, created on first use; '' when there is no folder. */
    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        $this->secret = '';
        if ($this->dir === '') {
            return '';
        }
        $file = rtrim($this->dir, '/\\') . '/session.key';
        $secret = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $secret)) {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            $secret = bin2hex(random_bytes(32));
            $old = umask(077);
            $written = @file_put_contents($file, $secret, LOCK_EX);
            umask($old);
            if ($written === false) {
                return '';
            }
        }
        return $this->secret = $secret;
    }
}
