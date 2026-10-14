<?php

namespace App\Service\Import;

use App\Entity\ImportDonnees;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import des données de démarrage (docs/import-donnees.md) : dépôt du
 * fichier, aperçu ligne par ligne, enregistrement en une transaction,
 * journal, annulation.
 *
 * Le fichier déposé attend dans var/imports/attente, au nom de la personne
 * qui l'a envoyé ; rien n'est écrit en base avant la confirmation. À la
 * confirmation, le fichier est relu et l'analyse refaite (la base a pu
 * changer entre-temps), puis tout est enregistré dans une seule
 * transaction avec la ligne du journal. Le fichier est alors rangé sous
 * var/uploads/imports (hors de public/), que seule la direction relit.
 */
final class Imports
{
    /** Un fichier déposé et pas importé est effacé au bout d'un jour. */
    private const ATTENTE_HEURES = 24;

    /** @var array<string, TypeImport> */
    private array $types = [];

    /**
     * @param iterable<TypeImport> $types
     */
    public function __construct(
        #[AutowireIterator('app.type_import')] iterable $types,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly LecteurTableur $lecteur,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
        foreach ($types as $t) {
            $this->types[$t->cle()] = $t;
        }
    }

    /** @return array<string, TypeImport> dans l'ordre d'affichage */
    public function types(): array
    {
        $ordre = ['clients', 'produits', 'articles', 'soldes_clients', 'soldes_comptes', 'soldes_fournisseurs'];
        $tries = [];
        foreach ($ordre as $cle) {
            if (isset($this->types[$cle])) {
                $tries[$cle] = $this->types[$cle];
            }
        }

        return $tries + $this->types;
    }

    public function type(string $cle): ?TypeImport
    {
        return $this->types[$cle] ?? null;
    }

    /**
     * @param callable(string): bool $droit
     */
    public static function accessible(TypeImport $type, callable $droit): bool
    {
        foreach ($type->droits() as $role) {
            if (!$droit($role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Range le fichier envoyé en attente d'aperçu.
     *
     * @param array{dateOuverture?: ?string, completer?: bool} $options
     *
     * @return string jeton de l'envoi
     *
     * @throws \InvalidArgumentException fichier refusé
     */
    public function deposer(TypeImport $type, UploadedFile $fichier, User $par, array $options, \DateTimeImmutable $maintenant): string
    {
        if (!$fichier->isValid()) {
            throw new \InvalidArgumentException(\UPLOAD_ERR_INI_SIZE === $fichier->getError() || \UPLOAD_ERR_FORM_SIZE === $fichier->getError()
                ? sprintf('Le fichier dépasse %d Mo.', LecteurTableur::TAILLE_MAX / 1024 / 1024)
                : 'Le fichier n\'est pas arrivé : renvoyez-le.');
        }
        $extension = strtolower(pathinfo((string) $fichier->getClientOriginalName(), \PATHINFO_EXTENSION));
        if (!\in_array($extension, LecteurTableur::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Envoyez un fichier Excel (.xlsx) ou CSV. Un ancien .xls s\'enregistre en .xlsx depuis Excel.');
        }
        $taille = (int) $fichier->getSize();
        if ($taille <= 0) {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }
        if ($taille > LecteurTableur::TAILLE_MAX) {
            throw new \InvalidArgumentException(sprintf('Le fichier dépasse %d Mo.', LecteurTableur::TAILLE_MAX / 1024 / 1024));
        }
        $this->nettoyerAttente($maintenant);
        $dossier = $this->dossierAttente();
        $jeton = bin2hex(random_bytes(16));
        $chemin = $dossier.'/'.$jeton.'.'.$extension;
        if (!@copy($fichier->getPathname(), $chemin)) {
            throw new \RuntimeException('Le fichier n\'a pas pu être rangé.');
        }
        @chmod($chemin, 0640);
        $date = null;
        if ($type->avecDateOuverture() && null !== ($options['dateOuverture'] ?? null) && '' !== $options['dateOuverture']) {
            $date = Valeurs::date((string) $options['dateOuverture']);
            if (null !== $date && $date > $maintenant->setTime(23, 59, 59)) {
                @unlink($chemin);
                throw new \InvalidArgumentException('Le jour d\'ouverture ne peut pas être dans le futur.');
            }
        }
        $meta = [
            'type' => $type->cle(),
            'nom' => self::nomPropre((string) $fichier->getClientOriginalName()),
            'extension' => $extension,
            'taille' => $taille,
            'empreinte' => (string) hash_file('sha256', $chemin),
            'par' => $par->getId(),
            'le' => $maintenant->format(\DATE_ATOM),
            'dateOuverture' => $date?->format('Y-m-d'),
            'completer' => $type->avecCompletion() && !empty($options['completer']),
        ];
        file_put_contents($dossier.'/'.$jeton.'.json', json_encode($meta, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        @chmod($dossier.'/'.$jeton.'.json', 0640);

        return $jeton;
    }

    /**
     * L'envoi en attente de cette personne, ou null.
     *
     * @return array{type: string, nom: string, extension: string, taille: int, empreinte: string, par: int, le: string, dateOuverture: ?string, completer: bool}|null
     */
    public function envoi(string $jeton, User $par): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $jeton)) {
            return null;
        }
        $fichier = $this->dossierAttente().'/'.$jeton.'.json';
        if (!is_file($fichier)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($fichier), true);
        if (!\is_array($meta) || ($meta['par'] ?? null) !== $par->getId() || !is_file($this->dossierAttente().'/'.$jeton.'.'.$meta['extension'])) {
            return null;
        }

        return $meta;
    }

    /** Efface le fichier déposé sans l'importer. */
    public function abandonner(string $jeton, User $par): void
    {
        $envoi = $this->envoi($jeton, $par);
        if (null === $envoi) {
            return;
        }
        @unlink($this->dossierAttente().'/'.$jeton.'.'.$envoi['extension']);
        @unlink($this->dossierAttente().'/'.$jeton.'.json');
    }

    /**
     * Ce que l'import ferait, ligne par ligne, sans rien écrire.
     *
     * @return array{type: TypeImport, envoi: array<string, mixed>, lecture: array{encodage: ?string, separateur: ?string}, colonnes: array<string, mixed>, lignes: list<LigneImport>, compte: array{lignes: int, crees: int, modifies: int, ignores: int, erreurs: int}, deja: list<ImportDonnees>, bloquant: ?string, avertissements: list<string>}
     *
     * @throws \InvalidArgumentException fichier illisible
     */
    public function apercu(TypeImport $type, string $jeton, array $envoi, ContexteImport $contexte): array
    {
        $chemin = $this->dossierAttente().'/'.$jeton.'.'.$envoi['extension'];
        $lu = $this->lecteur->lire($chemin, $envoi['extension']);
        $colonnes = Colonnes::associer($lu['entetes'], $type->colonnes(), $contexte->peut(...));
        $bloquant = null;
        $avertissements = [];
        if ([] !== $colonnes['manquantes']) {
            $bloquant = sprintf('Colonne%s obligatoire%s absente%s : %s. Partez du modèle à télécharger.', \count($colonnes['manquantes']) > 1 ? 's' : '', \count($colonnes['manquantes']) > 1 ? 's' : '', \count($colonnes['manquantes']) > 1 ? 's' : '', implode(', ', $colonnes['manquantes']));
        }
        if ([] === $colonnes['index']) {
            $bloquant = 'Aucune colonne reconnue : est-ce le bon fichier, et la première ligne est-elle bien l\'en-tête ?';
        }
        if ([] !== $colonnes['inconnues']) {
            $avertissements[] = sprintf('Colonne%s ignorée%s : %s.', \count($colonnes['inconnues']) > 1 ? 's' : '', \count($colonnes['inconnues']) > 1 ? 's' : '', implode(', ', $colonnes['inconnues']));
        }
        if ([] !== $colonnes['interdites']) {
            $avertissements[] = 'Les colonnes de coûts ne sont pas lues : il faut le droit de voir les coûts et marges.';
        }
        if ([] !== $colonnes['doublees']) {
            $avertissements[] = sprintf('Colonne%s en double, seule la première est lue : %s.', \count($colonnes['doublees']) > 1 ? 's' : '', implode(', ', $colonnes['doublees']));
        }

        $lignes = Colonnes::lignes($lu['lignes'], $colonnes['index']);
        $aAnalyser = [];
        foreach ($lignes as $i => $ligne) {
            // Les exemples sont les deux premières lignes du modèle : plus bas, une ligne pareille est une vraie ligne.
            if ($i < 2 && ModeleImport::estExemple($type, $ligne->valeurs)) {
                $ligne->libelle = 'Ligne d\'exemple du modèle';
                $ligne->ignorer('Ligne d\'exemple du modèle : écartée.');
                continue;
            }
            $aAnalyser[] = $ligne;
        }
        if (null === $bloquant && [] !== $aAnalyser) {
            $type->analyser($aAnalyser, $contexte);
        }
        if (null === $bloquant && [] === $aAnalyser) {
            $bloquant = 'Le fichier ne contient aucune ligne à importer.';
        }

        $compte = LigneImport::compter($lignes);
        if (null === $bloquant && $type->toutOuRien() && $compte['erreurs'] > 0) {
            $bloquant = sprintf('%d ligne%s en erreur : les soldes s\'importent en entier ou pas du tout. Corrigez le fichier et renvoyez-le.', $compte['erreurs'], $compte['erreurs'] > 1 ? 's' : '');
        }
        if (null === $bloquant && 0 === $compte['crees'] + $compte['modifies']) {
            $bloquant = 'Rien à enregistrer : toutes les lignes sont écartées ou en erreur.';
        }

        return [
            'type' => $type,
            'envoi' => $envoi,
            'lecture' => ['encodage' => $lu['encodage'], 'separateur' => $lu['separateur']],
            'colonnes' => $colonnes,
            'lignes' => $lignes,
            'compte' => $compte,
            'deja' => $this->dejaImportes($envoi['empreinte']),
            'bloquant' => $bloquant,
            'avertissements' => $avertissements,
        ];
    }

    /**
     * Imports réussis (pas annulés) du même fichier.
     *
     * @return list<ImportDonnees>
     */
    public function dejaImportes(string $empreinte): array
    {
        return $this->em->getRepository(ImportDonnees::class)->findBy(['empreinte' => $empreinte, 'resultat' => ImportDonnees::FAIT], ['le' => 'DESC']);
    }

    /**
     * Enregistre l'import confirmé : nouvelle lecture, nouvelle analyse,
     * puis tout dans une transaction. En cas d'échec, rien n'est gardé
     * sauf une ligne « échec » au journal.
     *
     * @throws \DomainException import refusé (le message dit pourquoi)
     */
    public function confirmer(TypeImport $type, string $jeton, array $envoi, ContexteImport $contexte, User $par): ImportDonnees
    {
        $apercu = $this->apercu($type, $jeton, $envoi, $contexte);
        if (null !== $apercu['bloquant']) {
            throw new \DomainException($apercu['bloquant']);
        }
        $source = $this->dossierAttente().'/'.$jeton.'.'.$envoi['extension'];
        $options = array_filter(['dateOuverture' => $envoi['dateOuverture'] ?? null, 'completer' => $envoi['completer'] ?: null, 'encodage' => $apercu['lecture']['encodage'], 'separateur' => $apercu['lecture']['separateur']], static fn ($v): bool => null !== $v);

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $import = (new ImportDonnees($type->cle(), $envoi['nom'], $envoi['empreinte'], (int) $envoi['taille'], $par, $contexte->parNom, $contexte->maintenant))
                ->compter($apercu['compte'])
                ->setOptions($options);
            $this->em->persist($import);
            $this->em->flush();
            $type->appliquer($apercu['lignes'], $contexte, $import);
            $import->setChemin($this->ranger($source, $import, $envoi['extension']));
            $this->em->flush();
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            $this->logger->error('Import {type} refusé par la base : {erreur}', ['type' => $type->cle(), 'erreur' => $e->getMessage(), 'exception' => $e]);
            $this->noterEchec($type, $envoi, $contexte, $par, $e);

            throw new \DomainException('L\'import a échoué, rien n\'a été enregistré : '.self::messageCourt($e), 0, $e);
        }
        @unlink($source);
        @unlink($this->dossierAttente().'/'.$jeton.'.json');

        return $import;
    }

    /**
     * Raisons qui empêchent d'annuler, vide si l'annulation est possible.
     *
     * @return list<string>
     */
    public function blocagesAnnulation(ImportDonnees $import): array
    {
        if (!$import->estFait()) {
            return ['Cet import n\'est pas enregistré (échec ou déjà annulé).'];
        }
        $type = $this->type($import->getType());
        if (null === $type) {
            return ['Type d\'import inconnu.'];
        }

        return $type->blocagesAnnulation($import);
    }

    /**
     * Défait l'import, en une transaction.
     *
     * @throws \DomainException annulation refusée
     */
    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        $blocages = $this->blocagesAnnulation($import);
        if ([] !== $blocages) {
            throw new \DomainException(implode(' ', $blocages));
        }
        $type = $this->type($import->getType()) ?? throw new \DomainException('Type d\'import inconnu.');
        $this->em->wrapInTransaction(function () use ($type, $import, $contexte): void {
            $type->annuler($import, $contexte);
            $import->annuler($contexte->par, $contexte->maintenant);
            $this->em->flush();
        });
    }

    /** @return list<ImportDonnees> */
    public function journal(int $nombre = 100): array
    {
        return $this->em->getRepository(ImportDonnees::class)->findBy([], ['le' => 'DESC', 'id' => 'DESC'], $nombre);
    }

    /** Chemin du fichier gardé pour l'audit, s'il existe encore. */
    public function cheminFichier(ImportDonnees $import): ?string
    {
        $relatif = (string) $import->getChemin();
        if (!preg_match('#^\d{4}/\d{2}/\d+-[0-9a-f]{8}\.(xlsx|csv|txt)$#', $relatif)) {
            return null;
        }
        $chemin = $this->dossierFichiers().'/'.$relatif;

        return is_file($chemin) ? $chemin : null;
    }

    public function dossierFichiers(): string
    {
        return $this->projet.'/var/uploads/imports';
    }

    private function dossierAttente(): string
    {
        $dossier = $this->projet.'/var/imports/attente';
        if (!is_dir($dossier) && !@mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier d\'attente des imports impossible à créer.');
        }

        return $dossier;
    }

    /** Range le fichier importé : var/uploads/imports/AAAA/MM/<id>-<empreinte>.<ext>. */
    private function ranger(string $source, ImportDonnees $import, string $extension): string
    {
        $relatif = sprintf('%s/%d-%s.%s', $import->getLe()->format('Y/m'), (int) $import->getId(), substr($import->getEmpreinte(), 0, 8), $extension);
        $cible = $this->dossierFichiers().'/'.$relatif;
        if (!is_dir(\dirname($cible)) && !@mkdir(\dirname($cible), 0750, true) && !is_dir(\dirname($cible))) {
            throw new \RuntimeException('Dossier des imports impossible à créer.');
        }
        if (!@copy($source, $cible)) {
            throw new \RuntimeException('Le fichier importé n\'a pas pu être gardé.');
        }
        @chmod($cible, 0640);

        return $relatif;
    }

    private function noterEchec(TypeImport $type, array $envoi, ContexteImport $contexte, User $par, \Throwable $e): void
    {
        try {
            $em = $this->em->isOpen() ? $this->em : $this->doctrine->resetManager();
            if (!$em instanceof EntityManagerInterface) {
                return;
            }
            $em->clear();
            $personne = null !== $par->getId() ? $em->find(User::class, $par->getId()) : null;
            $echec = (new ImportDonnees($type->cle(), $envoi['nom'], $envoi['empreinte'], (int) $envoi['taille'], $personne, $contexte->parNom, $contexte->maintenant))
                ->echouer(self::messageCourt($e));
            $em->persist($echec);
            $em->flush();
        } catch (\Throwable $f) {
            $this->logger->error('Échec d\'import non noté au journal : {erreur}', ['erreur' => $f->getMessage()]);
        }
    }

    private function nettoyerAttente(\DateTimeImmutable $maintenant): void
    {
        $limite = $maintenant->getTimestamp() - self::ATTENTE_HEURES * 3600;
        foreach (glob($this->dossierAttente().'/*') ?: [] as $f) {
            if (is_file($f) && (int) filemtime($f) < $limite) {
                @unlink($f);
            }
        }
    }

    private static function messageCourt(\Throwable $e): string
    {
        $m = $e instanceof \DomainException || $e instanceof \RuntimeException && !$e instanceof \Doctrine\DBAL\Exception ? $e->getMessage() : 'la base a refusé l\'enregistrement.';

        return mb_substr($m, 0, 500);
    }

    /** Nom d'origine, sans chemin ni caractères de contrôle. */
    public static function nomPropre(string $nom): string
    {
        $nom = basename(str_replace('\\', '/', $nom));
        $nom = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $nom);

        return '' !== trim($nom) ? mb_substr(trim($nom), 0, 200) : 'fichier';
    }
}
