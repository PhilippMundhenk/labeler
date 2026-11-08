<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Optional password for the interface: a stateless signed cookie, valid for 30 days.
 * The key is derived from the password, so changing it logs everybody out.
 */
final class Session
{
    public const COOKIE = 'labeler_session';
    public const LIFETIME = 30 * 24 * 3600;

    private string $password;

    /** @param string $password plain text or a hash from PHP's password_hash() */
    public function __construct(string $password)
    {
        $this->password = $password;
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
        return hash_hmac('sha256', $expires, hash('sha256', 'labeler-session|' . $this->password));
    }
}
