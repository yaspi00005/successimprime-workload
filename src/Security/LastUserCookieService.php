<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LastUserCookieService
{
    public const COOKIE_NAME = 'successimprim_last_user';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret
    ) {
    }

    public function createValue(User $user): string
    {
        if ($user->getId() === null) {
            throw new \LogicException(
                'Impossible de mémoriser un utilisateur sans identifiant.'
            );
        }

        $payload = $this->base64UrlEncode(
            (string) $user->getId()
        );

        $signature = hash_hmac(
            'sha256',
            $payload,
            $this->secret
        );

        return $payload . '.' . $signature;
    }

    public function getUserId(
        ?string $cookieValue
    ): ?int {
        if (
            $cookieValue === null
            ||
            trim($cookieValue) === ''
        ) {
            return null;
        }

        $parts = explode(
            '.',
            $cookieValue,
            2
        );

        if (count($parts) !== 2) {
            return null;
        }

        [
            $payload,
            $signature,
        ] = $parts;

        $expectedSignature = hash_hmac(
            'sha256',
            $payload,
            $this->secret
        );

        if (
            !hash_equals(
                $expectedSignature,
                $signature
            )
        ) {
            return null;
        }

        $decoded = $this->base64UrlDecode(
            $payload
        );

        if (
            $decoded === null
            ||
            !ctype_digit($decoded)
        ) {
            return null;
        }

        $id = (int) $decoded;

        return $id > 0
            ? $id
            : null;
    }

    private function base64UrlEncode(
        string $value
    ): string {
        return rtrim(
            strtr(
                base64_encode($value),
                '+/',
                '-_'
            ),
            '='
        );
    }

    private function base64UrlDecode(
        string $value
    ): ?string {
        $value = strtr(
            $value,
            '-_',
            '+/'
        );

        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $value,
            true
        );

        return $decoded === false
            ? null
            : $decoded;
    }
}