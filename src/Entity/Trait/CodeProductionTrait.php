<?php

namespace App\Entity\Trait;

use App\Service\Production\NomFichierProduction;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code court d'un support ou d'une finition dans le nom des fichiers de
 * production (BACHE440, OEIL50 : docs/production-rouleau.md). Vide : le
 * code est tiré du nom. Réglé sur « Règles de nommage » ; reste au local
 * (absent de CatalogueSynchro).
 */
trait CodeProductionTrait
{
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $codeProduction = null;

    public function getCodeProduction(): ?string
    {
        return $this->codeProduction;
    }

    public function setCodeProduction(?string $code): static
    {
        $code = NomFichierProduction::nettoyerCode($code);
        $this->codeProduction = '' === $code ? null : $code;

        return $this;
    }

    /** Le code utilisé dans les noms : le code réglé, sinon celui tiré du nom. */
    public function codePourFichier(): string
    {
        return NomFichierProduction::codeRegle($this->codeProduction, (string) $this->getNom());
    }
}
