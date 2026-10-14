<?php

namespace App\Service\Site;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Entity\Finition;
use App\Entity\SynchroJournal;
use App\Repository\CompteClientRepository;
use App\Service\AnalyseFichierImpression;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\GrilleTarifaire;
use App\Service\NotificationService;
use App\Service\OrangeSmsService;
use App\Service\Synchro\CatalogueSynchro;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Demandes écrites sur le site sans compte (maquette 11), de l'envoi par
 * le visiteur à leur préparation au local.
 *
 * En ligne : la demande et son fichier sont enregistrés, un SMS d'accusé
 * de réception part si le service SMS est réglé (jamais de montant, jamais
 * vers une fiche de démonstration), puis la demande monte au local par la
 * synchronisation. Au local : PreparationDemande en fait un prospect et un
 * devis brouillon, et les personnes qui font les devis sont prévenues.
 */
final class DemandesSite
{
    /** Fichier joint au plus (envoi simple, pas par morceaux comme « Mes fichiers »). */
    public const FICHIER_MAX = 20 * 1024 * 1024;

    /** Accusé de réception envoyé par le local seulement pour une demande récente. */
    public const ACCUSE_HEURES = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyseFichierImpression $analyse,
        private readonly OrangeSmsService $sms,
        private readonly NotificationService $notifications,
        private readonly CompteClientRepository $comptes,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.site_accuse_telephone')] private readonly RateLimiterFactoryInterface $limiteAccuse,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /** Taille permise : la nôtre, ou moins si le serveur n'accepte pas plus. */
    public static function tailleMaxFichier(): int
    {
        return (int) min(self::FICHIER_MAX, UploadedFile::getMaxFilesize());
    }

    /**
     * Mêmes formats et même contrôle du contenu que « Mes fichiers ».
     *
     * @throws \DomainException
     */
    public static function verifierFichier(UploadedFile $fichier): void
    {
        $max = self::tailleMaxFichier();
        if (!$fichier->isValid()) {
            throw new \DomainException(\in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? \sprintf('Le fichier dépasse %d Mo : envoyez-le par WhatsApp ou collez un lien dans le message.', intdiv($max, 1024 * 1024))
                : 'Le fichier n\'est pas arrivé : réessayez.');
        }
        $extension = strtolower(pathinfo(FichiersClient::nomPropre((string) $fichier->getClientOriginalName()), \PATHINFO_EXTENSION));
        if (!\in_array($extension, FichiersClient::extensions(), true)) {
            throw new \DomainException('Format non accepté. Envoyez un PDF, AI, EPS, PSD, JPG, PNG ou TIFF.');
        }
        $taille = (int) $fichier->getSize();
        if ($taille <= 0) {
            throw new \DomainException('Ce fichier est vide.');
        }
        if ($taille > $max) {
            throw new \DomainException(\sprintf('Le fichier dépasse %d Mo : envoyez-le par WhatsApp ou collez un lien dans le message.', intdiv($max, 1024 * 1024)));
        }
        if (!FichiersClient::signatureValide($extension, (string) file_get_contents($fichier->getPathname(), false, null, 0, 1024))) {
            throw new \DomainException(\sprintf('Ce fichier n\'est pas un vrai %s : enregistrez-le de nouveau depuis votre logiciel.', strtoupper($extension)));
        }
    }

    /**
     * Enregistre la demande contrôlée (FormulaireDemande::valider).
     *
     * @param array<string, mixed> $valeurs
     *
     * @throws \DomainException
     */
    public function enregistrer(string $nature, array $valeurs, ?UploadedFile $fichier, \DateTimeImmutable $le): DemandeDevis
    {
        if (null !== $fichier) {
            self::verifierFichier($fichier);
        }
        $produit = $valeurs['produit'] ?? null;
        $demande = DemandeDevis::nouvelle($nature, (string) $valeurs['nom'], (string) $valeurs['telephone'], $le)
            ->setEmail($valeurs['email'] ?? null)
            ->setObjet($valeurs['objet'] ?? null)
            ->setMessage($valeurs['message'] ?? null);
        if (null !== $produit) {
            $demande->setConfiguration($produit)->setDesignation(GrilleTarifaire::libelle($produit))
                ->setTaille($valeurs['largeur'] ?? null, $valeurs['hauteur'] ?? null)
                ->setQuantite($valeurs['quantite'] ?? null);
            // Les finitions partent par leur finition du catalogue (même identifiant public des deux côtés).
            foreach ($produit->getConfigurationFinitions() as $cf) {
                if (\in_array($cf->getId(), $valeurs['finitions'] ?? [], true) && $cf->getFinition() instanceof Finition) {
                    $demande->addFinition($cf->getFinition());
                }
            }
        } elseif (null !== ($valeurs['quantite'] ?? null)) {
            $demande->setQuantite($valeurs['quantite']);
        }

        $chemin = null;
        try {
            $this->em->wrapInTransaction(function () use ($demande, $fichier, $valeurs, &$chemin): void {
                if (null !== $fichier) {
                    [$f, $chemin] = $this->ranger($fichier, $valeurs['largeur'] ?? null, $valeurs['hauteur'] ?? null);
                    $demande->setFichier($f);
                }
                $this->em->persist($demande);
                $this->em->flush();
                // Le numéro vient de l'id ; en ligne il reçoit le préfixe W-.
                $demande->setNumero(\sprintf('DD-%04d', $demande->getId()));
                $this->em->flush();
            });
        } catch (\Throwable $e) {
            if (null !== $chemin) {
                @unlink($chemin);
            }
            throw $e;
        }

        $this->accuser($demande, $le);
        if (!$this->enLigne) {
            // Écrite sur l'aperçu du site au local : préparée tout de suite.
            $this->preparer($demande);
        }

        return $demande;
    }

    /**
     * SMS d'accusé de réception, sans montant. Jamais vers une fiche de
     * démonstration, ni plus de 3 fois par jour vers le même numéro (il a
     * été tapé par un inconnu). Ne fait jamais échouer la demande.
     */
    public function accuser(DemandeDevis $demande, \DateTimeImmutable $le): bool
    {
        if (null !== $demande->getAccuseEnvoyeLe() || !$this->sms->estConfigure()) {
            return false;
        }
        $client = $this->comptes->clientDuTelephone($demande->getTelephone());
        if (self::estDeDemo($client) || (null !== $client && !$client->isRecevoirSms())) {
            return false;
        }
        if (!$this->limiteAccuse->create($demande->getTelephone())->consume()->isAccepted()) {
            return false;
        }
        try {
            $envoi = $this->sms->envoyerSms($demande->getTelephone(), self::texteAccuse($demande));
        } catch (\Throwable $e) {
            $this->logger->warning('Accusé de réception de la demande {numero} non envoyé : {erreur}', ['numero' => $demande->getNumero(), 'erreur' => $e->getMessage()]);

            return false;
        }
        if (!($envoi['succes'] ?? false)) {
            return false;
        }
        $demande->noterAccuseEnvoye($le);
        $this->em->flush();

        return true;
    }

    public static function texteAccuse(DemandeDevis $demande): string
    {
        return \sprintf(
            'Succès Imprim : nous avons bien reçu votre %s %s. Nous vous répondons pendant nos heures d\'ouverture.',
            $demande->estDevis() ? 'demande de devis' : 'message',
            $demande->getNumero(),
        );
    }

    /** Fiche de démonstration (app:demo, comptes d'essai) : aucun message. */
    public static function estDeDemo(?Clients $client): bool
    {
        return null !== $client && (NotificationsClient::estDemo($client) || str_starts_with((string) $client->getCode(), 'CL-DEMO'));
    }

    /**
     * Au local, une seule fois : client du même numéro ou prospect, devis
     * brouillon, personnes des devis prévenues. Pendant la réception d'une
     * synchronisation ($journaliser), la file d'envoi est coupée : la fiche
     * client et le devis créés y sont mis à la main, pour descendre au site.
     *
     * @return string ce qui a été fait, pour le journal des échanges
     */
    public function preparer(DemandeDevis $demande, bool $journaliser = false): string
    {
        if (null !== $demande->getPrepareeLe()) {
            return 'Déjà préparée';
        }
        $le = new \DateTimeImmutable();
        $r = PreparationDemande::construire($demande, $this->comptes->clientDuTelephone($demande->getTelephone()), $le);
        $aEnvoyer = [];

        if ($r['nouveauClient']) {
            $this->em->persist($r['client']);
            $this->em->flush();
            $r['client']->genererCodeDepuisId();
            $aEnvoyer[] = $r['client'];
        }
        if (null !== $r['prospect']) {
            $this->em->persist($r['prospect']);
        }
        $devis = $r['devis'];
        if ($devis instanceof Devis) {
            $this->em->persist($devis);
            $this->em->flush();
            $devis->setNumero(\sprintf('DEV-%06d-%s', $devis->getId(), $le->format('m-Y')));
            array_push($aEnvoyer, $devis, ...$devis->getDevisDetails()->toArray());
        }
        $demande->preparer($r['client'], $r['prospect'], $devis, $le);

        $qui = $demande->getNom().(null !== $r['client'] && !$r['nouveauClient'] ? ', client '.$r['client']->getCode() : '');
        $message = $demande->estDevis()
            ? \sprintf('Demande de devis %s du site (%s) : devis brouillon %s à vérifier avant tout envoi.', $demande->getNumero(), $qui, $devis?->getNumero())
            : \sprintf('Message %s du site (%s) : %s.', $demande->getNumero(), $qui, $demande->getLibelleObjet() ?? 'question');
        $this->notifications->notifierRoles(['ROLE_DEVIS', 'ROLE_ADMIN'], $message, 'app_demandes_site_voir', ['id' => $demande->getId()]);

        if ($journaliser) {
            foreach ($aEnvoyer as $entite) {
                $this->em->persist(new SynchroJournal((string) CatalogueSynchro::typeDe($entite), $entite->getPublicId()->toRfc4122(), SynchroJournal::MAJ));
            }
        }
        $this->em->flush();

        // Le site n'a pas pu l'envoyer (SMS réglé seulement ici) : la boutique accuse réception.
        if ($demande->getCreeLe() > $le->modify(\sprintf('-%d hours', self::ACCUSE_HEURES))) {
            $this->accuser($demande, $le);
        }

        return match (true) {
            $devis instanceof Devis => \sprintf('Devis brouillon %s%s', $devis->getNumero(), null !== $r['prospect'] ? ' et prospect' : ' pour le client '.$r['client']?->getCode()),
            null !== $r['prospect'] => 'Message rangé dans les prospects',
            default => 'Message à lire',
        };
    }

    /**
     * Range le fichier hors de public/, comme ceux de « Mes fichiers »,
     * avec son empreinte et le contrôle automatique.
     *
     * @return array{0: CommandeDetailFichier, 1: string} le fichier et son chemin
     */
    private function ranger(UploadedFile $fichier, ?float $largeurCm, ?float $hauteurCm): array
    {
        $nom = FichiersClient::nomPropre((string) $fichier->getClientOriginalName());
        $extension = strtolower(pathinfo($nom, \PATHINFO_EXTENSION));
        $jeton = bin2hex(random_bytes(32));
        $dossier = $this->projet.'/var/uploads/commandes';
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des fichiers impossible à créer');
        }
        $taille = (int) $fichier->getSize();
        $chemin = $fichier->move($dossier, $jeton.'.'.$extension)->getPathname();
        chmod($chemin, 0640);

        $f = (new CommandeDetailFichier())
            ->setJetonUpload($jeton)
            ->setNomOriginal($nom)
            ->setNomStockage($jeton.'.'.$extension)
            ->setTypeMime(FichiersClient::MIME[$extension])
            ->setTaille($taille)
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setOrigine(CommandeDetailFichier::ORIGINE_CLIENT)
            ->setEmpreinte((string) hash_file('sha256', $chemin))
            ->setAnalyse($this->analyse->analyser($chemin, $largeurCm, $hauteurCm))
            ->marquerUploadTermine();
        $this->em->persist($f);

        return [$f, $chemin];
    }
}
