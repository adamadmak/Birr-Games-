<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which language the player sees.
 *
 * Order of preference:
 *   1. An explicit choice, remembered in a cookie for a year.
 *   2. The device's Accept-Language header. Inside the Telegram mini app this
 *      reflects the language the player has already chosen for Telegram, so an
 *      Amharic-speaking user lands on Amharic without touching a setting.
 *   3. The configured default.
 *
 * A cookie rather than a users column deliberately: it works for signed-out
 * visitors too, and it needs no migration to be safe to deploy.
 */
class SetLocale
{
    public const COOKIE = 'lucy_locale';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    /** Locales the player-facing site is actually translated into. */
    public static function supported(): array
    {
        return [
            'am' => ['name' => 'አማርኛ', 'english' => 'Amharic', 'script' => 'ethiopic'],
            'en' => ['name' => 'English', 'english' => 'English', 'script' => 'latin'],
        ];
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::supported());
    }

    private function resolve(Request $request): string
    {
        $chosen = $request->cookie(self::COOKIE);

        if (self::isSupported($chosen)) {
            return $chosen;
        }

        foreach ($this->acceptedLanguages($request) as $language) {
            if (self::isSupported($language)) {
                return $language;
            }
        }

        return config('app.locale', 'en');
    }

    /**
     * Accept-Language, highest quality first. "am-ET" and "am" both count as
     * Amharic, so only the primary subtag is compared.
     *
     * @return array<int, string>
     */
    private function acceptedLanguages(Request $request): array
    {
        $header = (string) $request->header('Accept-Language', '');

        if ($header === '') {
            return [];
        }

        $ranked = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag = strtolower(trim($bits[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $ranked[] = [
                'tag' => explode('-', $tag)[0],
                'q' => isset($bits[1]) ? (float) $bits[1] : 1.0,
            ];
        }

        usort($ranked, fn ($a, $b) => $b['q'] <=> $a['q']);

        return array_column($ranked, 'tag');
    }
}
