<?php

namespace App\Twig;

use App\Routing\CodeId;
use Twig\Attribute\AsTwigFilter;

/**
 * « {{ c.id|code_id }} » : le code chiffré d'un numéro de fiche, pour une
 * URL écrite à la main dans un gabarit ou un script (docs/ids-chiffres.md).
 * path() chiffre déjà tout seul.
 */
final class IdsChiffresExtension
{
    public function __construct(private readonly CodeId $codes)
    {
    }

    #[AsTwigFilter('code_id')]
    public function codeId(int|string|null $id): string
    {
        return null !== $id && '' !== (string) $id && ctype_digit((string) $id) ? $this->codes->coder((int) $id) : (string) $id;
    }
}
