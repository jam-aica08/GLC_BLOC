<?php

namespace App\Libraries;

/**
 * Strength rule for a temporary password set by the superadmin. The reply
 * never repeats the password itself.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** bcrypt (PASSWORD_DEFAULT) ignores everything after 72 bytes. */
    public const MAX_BYTES = 72;

    public const DESCRIPTION = 'At least 8 characters, with an uppercase letter, a lowercase letter, a number and a symbol.';

    /** Why the password is too weak, or null when it is acceptable. */
    public static function problem(string $password, string $username = ''): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'The temporary password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'The temporary password must be at most ' . self::MAX_BYTES . ' characters.';
        }
        if (preg_match('/\s/u', $password)) {
            return 'The temporary password cannot contain spaces.';
        }
        if (! preg_match('/\p{Lu}/u', $password) || ! preg_match('/\p{Ll}/u', $password)
            || ! preg_match('/\d/', $password) || ! preg_match('/[^\p{L}\d]/u', $password)) {
            return 'The temporary password must include an uppercase letter, a lowercase letter, a number and a symbol.';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            return 'The temporary password cannot contain the member\'s username.';
        }

        return null;
    }
}
