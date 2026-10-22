<?php

namespace App\Tests\Unit\Service;

use App\Entity\Prospect;
use App\Service\PipelineProspects;
use PHPUnit\Framework\TestCase;

final class PipelineProspectsTest extends TestCase
{
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        $this->maintenant = new \DateTimeImmutable('2026-10-11 10:00');
    }

    public function testPerduExigeUnMotifEtCloture(): void
    {
        $p = (new Prospect())->setNom('Garage Sahel')->setProchaineRelance(new \DateTimeImmutable('2026-10-12'));
        try {
            $p->changerEtape(Prospect::ETAPE_PERDU, '  ');
            self::fail('Un motif est obligatoire.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(Prospect::ETAPE_NOUVEAU, $p->getEtape());

        $p->changerEtape(Prospect::ETAPE_PERDU, 'prix', $this->maintenant);
        self::assertSame('prix', $p->getMotifPerte());
        self::assertEquals($this->maintenant, $p->getClotureLe());
        self::assertNull($p->getProchaineRelance());

        $p->changerEtape(Prospect::ETAPE_NEGOCIATION);
        self::assertNull($p->getClotureLe());
        self::assertNull($p->getMotifPerte());
    }

    public function testRestePerduGardeLaDateDeCloture(): void
    {
        $p = (new Prospect())->setNom('X');
        $p->changerEtape(Prospect::ETAPE_PERDU, 'délai', $this->maintenant->modify('-10 days'));
        $p->changerEtape(Prospect::ETAPE_PERDU, 'délai trop long', $this->maintenant);
        self::assertEquals($this->maintenant->modify('-10 days'), $p->getClotureLe());
        self::assertSame('délai trop long', $p->getMotifPerte());
    }

    public function testSourceEtTelephoneNormalises(): void
    {
        $p = (new Prospect())->setSource('inconnue')->setTelephone('+223 76 12-34-56');
        self::assertSame('autre', $p->getSource());
        self::assertSame('+22376123456', $p->getTelephone());
    }

    public function testIndicateursEtColonnes(): void
    {
        $prospects = [
            $this->prospect('Hôtel Niger', 'site', 200000, Prospect::ETAPE_NOUVEAU, '-5 days', relance: 'today'),
            $this->prospect('Awa Mode', 'site', 125000, Prospect::ETAPE_DEVIS_ENVOYE, '-8 days', relance: '+3 days'),
            $this->prospect('ONG', 'whatsapp', 190000, Prospect::ETAPE_NEGOCIATION, '-20 days', relance: '-2 days'),
            $this->prospect('Club', 'whatsapp', 160000, Prospect::ETAPE_GAGNE, '-30 days', cloture: '-10 days'),
            $this->prospect('Mairie', 'boutique', 96000, Prospect::ETAPE_GAGNE, '-40 days', cloture: '-12 days'),
            $this->prospect('Garage', 'boutique', 50000, Prospect::ETAPE_PERDU, '-60 days', cloture: '-15 days'),
            // Fenêtre précédente : 1 gagné sur 2.
            $this->prospect('Ancien gagné', 'site', 10000, Prospect::ETAPE_GAGNE, '-200 days', cloture: '-120 days'),
            $this->prospect('Ancien perdu', 'site', 10000, Prospect::ETAPE_PERDU, '-200 days', cloture: '-130 days'),
        ];

        $r = PipelineProspects::calculer($prospects, $this->maintenant);

        self::assertSame(515000, $r['kpi']['pipeline']);
        self::assertSame(3, $r['kpi']['ouverts']);
        self::assertSame(67, $r['kpi']['taux']);
        self::assertSame(17, $r['kpi']['evolution']);
        self::assertSame(2, $r['kpi']['relances_dues']);
        self::assertSame('site', $r['kpi']['source']['source']);
        self::assertSame(33, $r['kpi']['source']['pourcentage']);
        self::assertCount(2, $r['colonnes'][Prospect::ETAPE_GAGNE]);
        self::assertCount(1, $r['colonnes'][Prospect::ETAPE_PERDU], 'Les affaires closes il y a plus de 90 jours sont masquées.');

        $filtre = PipelineProspects::calculer($prospects, $this->maintenant, 'awa');
        self::assertSame(['Awa Mode'], array_map(static fn (Prospect $p): string => $p->getNom(), array_merge(...array_values($filtre['colonnes']))));
        self::assertSame(515000, $filtre['kpi']['pipeline'], 'La recherche ne change pas les chiffres clés.');
    }

    public function testTauxSansAffaireClose(): void
    {
        self::assertNull(PipelineProspects::taux(0, 0));
        self::assertSame(100, PipelineProspects::taux(3, 0));
    }

    private function prospect(string $nom, string $source, int $montant, string $etape, string $cree, ?string $cloture = null, ?string $relance = null): Prospect
    {
        $p = (new Prospect())
            ->setNom($nom)
            ->setSource($source)
            ->setMontantEstime($montant)
            ->setCreeLe($this->maintenant->modify($cree));
        if (null !== $relance) {
            $p->setProchaineRelance('today' === $relance ? $this->maintenant->setTime(0, 0) : $this->maintenant->setTime(0, 0)->modify($relance));
        }
        if (Prospect::ETAPE_NOUVEAU !== $etape) {
            $p->changerEtape($etape, 'motif', $this->maintenant->modify($cloture ?? '-1 day'));
        }

        return $p;
    }
}
