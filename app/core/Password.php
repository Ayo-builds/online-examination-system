<?php
/**
 * Initial and reset passwords, in one place.
 *
 * Both the bulk student import and the admin reset actions hand out a
 * generated password on a printed slip. Two generators would drift: one would
 * gain a character class or lose a length while the other did not, and the
 * slips coming out of the two paths would stop being interchangeable. There is
 * one policy, so there is one implementation.
 */
class Password
{
    // Ambiguous glyphs are left out: no I or 1, no O or 0. These are read off a
    // printed slip by a teenager and typed into a browser, and a credential
    // that cannot be typed is a support call, not security.
    private const ALPHABET  = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const GROUPS    = 3;
    private const GROUP_LEN = 4;

    /**
     * A grouped, legible password: K7M4-P2QX-9RTB.
     *
     * 12 characters from a 31-glyph alphabet is a little under 60 bits. The app
     * has no password-change screen, so this is the credential the holder keeps
     * until an admin resets it, and it is sized for that rather than for a
     * value they will replace on first sign-in.
     */
    public static function generate(): string
    {
        $max    = strlen(self::ALPHABET) - 1;
        $groups = [];

        for ($g = 0; $g < self::GROUPS; $g++) {
            $chunk = '';
            for ($i = 0; $i < self::GROUP_LEN; $i++) {
                // random_int, not rand: this is a credential.
                $chunk .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    public static function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_DEFAULT);
    }
}
