<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * Makes a value safe to send: the value of a field whose name is on the list becomes `[scrubbed]` (the key stays),
 * objects are never dumped, depth and size are bounded, and obvious secrets inside free text are redacted.
 *
 * Names compare in lower case, letters and digits only (`X-Api-Key` is `xapikey`). A name that contains one of
 * SECRET_PARTS is scrubbed, the names in EXACT and the names the application adds match as a whole.
 *
 * @internal
 */
final class Scrubber
{
    public const REPLACEMENT = '[scrubbed]';

    private const SECRET_PARTS = ['password', 'passwd', 'secret', 'token', 'apikey', 'authorization', 'cookie', 'credential', 'privatekey', 'csrf', 'xsrf'];
    private const EXACT = [
        'pwd', 'pin', 'session', 'sessionid', 'phpsessid', 'cvv', 'cvc', 'iban', 'ssn', 'creditcard', 'cardnumber',
        'email', 'emailaddress', 'mail', 'phone', 'phonenumber', 'mobile', 'telephone',
        'firstname', 'lastname', 'fullname', 'username', 'login',
    ];
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 50;
    private const MAX_STRING = 500;

    /** @var array<string, true> */
    private $exact = [];

    /**
     * @param list<string> $extraNames names the application adds
     */
    public function __construct(array $extraNames = [])
    {
        foreach (array_merge(self::EXACT, array_map([self::class, 'normalise'], $extraNames)) as $name) {
            $this->exact[$name] = true;
        }
    }

    /**
     * @param mixed $value
     *
     * @return mixed a value json_encode() takes
     */
    public function value($value, int $depth = 0)
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[truncated]';
            }
            $result = [];
            foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $key => $item) {
                $result[$key] = is_string($key) && $this->isSensitive($key) ? self::REPLACEMENT : $this->value($item, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            return $this->text($value, self::MAX_STRING);
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (null === $value || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_object($value)) {
            return '[object '.self::className($value).']';
        }

        return '['.get_resource_type($value).' resource]';
    }

    /**
     * Free text cannot be scrubbed by name, so only what is clearly a secret goes: bearer tokens, sonar tokens,
     * `password=...` style pairs, credentials inside a URL and e-mail addresses. Then it is cut to `$max` bytes.
     */
    public function text(string $text, int $max): string
    {
        $r = self::REPLACEMENT;
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{6,}/i', 'Bearer '.$r, $text);
        $text = (string) preg_replace('/\bsonar_[a-z]{3}_[A-Za-z0-9]+/', $r, $text);
        $text = (string) preg_replace('/(\b[\w.-]*(?:password|passwd|pwd|secret|token|api[_-]?key|authorization)[\w.-]*)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&"\']+)/i', '$1$2'.$r, $text);
        $text = (string) preg_replace('#(\b[a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#i', '$1'.$r.'@', $text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $r, $text);

        return self::cut($text, $max);
    }

    public static function cut(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        $cut = substr($text, 0, $max);
        // never end inside a multibyte character: sonar refuses invalid UTF-8
        while ('' !== $cut && !preg_match('//u', $cut)) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /**
     * @param object $object
     */
    public static function className($object): string
    {
        $class = get_class($object);
        $end = strpos($class, "\0");

        return false === $end ? $class : substr($class, 0, $end);
    }

    private function isSensitive(string $key): bool
    {
        $name = self::normalise($key);
        if (isset($this->exact[$name])) {
            return true;
        }
        foreach (self::SECRET_PARTS as $part) {
            if (false !== strpos($name, $part)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
}
