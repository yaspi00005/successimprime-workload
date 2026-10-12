<?php

namespace App\Service\Synchro;

use App\Entity\SynchroJournal;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remplit la file d'envoi dans la même transaction que la modification :
 * si l'enregistrement réussit, l'envoi est garanti ; s'il échoue, rien ne
 * part.
 *
 * Les données reçues de l'autre côté ne sont pas renvoyées (pas d'écho) :
 * Synchro coupe l'écoute pendant qu'il les applique.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class JournalSynchro
{
    private bool $actif = true;

    public function __construct(
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /** Exécute $f sans remplir la file (application de données reçues). */
    public function sansEcho(callable $f): mixed
    {
        $avant = $this->actif;
        $this->actif = false;
        try {
            return $f();
        } finally {
            $this->actif = $avant;
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->actif) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $aNoter = [];

        if ($this->enLigne) {
            foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entite) {
                $this->prefixerNumero($em, $entite);
            }
        }

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            $this->ajouter($aNoter, $entite, SynchroJournal::MAJ);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            $type = CatalogueSynchro::typeDe($entite);
            if ($type !== null && array_intersect(array_keys($uow->getEntityChangeSet($entite)), CatalogueSynchro::champsSuivis($type)) !== []) {
                $this->ajouter($aNoter, $entite, SynchroJournal::MAJ);
            }
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            /** @var PersistentCollection $collection */
            $proprietaire = $collection->getOwner();
            $type = $proprietaire === null ? null : CatalogueSynchro::typeDe($proprietaire);
            if ($type !== null && isset(CatalogueSynchro::types()[$type]['listes'][$collection->getMapping()['fieldName']])) {
                $this->ajouter($aNoter, $proprietaire, SynchroJournal::MAJ);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            $this->ajouter($aNoter, $entite, SynchroJournal::SUPPRESSION);
        }

        if ($aNoter === []) {
            return;
        }
        $meta = $em->getClassMetadata(SynchroJournal::class);
        foreach ($aNoter as $journal) {
            $em->persist($journal);
            $uow->computeChangeSet($meta, $journal);
        }
    }

    /**
     * Sur le site, un numéro attribué à une fiche créée en ligne reçoit le
     * préfixe W- : il ne peut jamais prendre celui d'une fiche du local
     * (les numéros viennent des id, différents dans les deux bases).
     */
    private function prefixerNumero(\Doctrine\ORM\EntityManagerInterface $em, object $entite): void
    {
        $type = CatalogueSynchro::typeDe($entite);
        $champ = $type === null ? null : (CatalogueSynchro::types()[$type]['numero'] ?? null);
        if ($champ === null) {
            return;
        }
        $uow = $em->getUnitOfWork();
        // Une fiche venue du local garde son numéro : seul un numéro qui vient d'être attribué ici est préfixé.
        if (!$uow->isScheduledForInsert($entite) && !\array_key_exists($champ, $uow->getEntityChangeSet($entite))) {
            return;
        }
        $meta = $em->getClassMetadata($entite::class);
        $valeur = $meta->getFieldValue($entite, $champ);
        if (!self::aPrefixer($valeur)) {
            return;
        }
        $meta->setFieldValue($entite, $champ, self::PREFIXE.$valeur);
        $uow->recomputeSingleEntityChangeSet($meta, $entite);
    }

    public const PREFIXE = 'W-';

    public static function aPrefixer(mixed $valeur): bool
    {
        return \is_string($valeur) && $valeur !== '' && !str_starts_with($valeur, self::PREFIXE) && !str_starts_with($valeur, 'TMP-');
    }

    /** @param array<string, SynchroJournal> $aNoter */
    private function ajouter(array &$aNoter, object $entite, string $operation): void
    {
        $type = CatalogueSynchro::typeDe($entite);
        if ($type === null || !CatalogueSynchro::envoye($type, $this->enLigne)) {
            return;
        }
        $publicId = $entite->getPublicId()->toRfc4122();
        // Une suppression l'emporte sur une modification dans le même enregistrement.
        if (isset($aNoter[$type.$publicId]) && $operation !== SynchroJournal::SUPPRESSION) {
            return;
        }
        $aNoter[$type.$publicId] = new SynchroJournal($type, $publicId, $operation);
    }
}
