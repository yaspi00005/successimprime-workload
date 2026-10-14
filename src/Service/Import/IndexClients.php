<?php

namespace App\Service\Import;

use App\Entity\Clients;
use App\Entity\ContactClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le fichier client en mémoire, pour retrouver une fiche sans une requête
 * par ligne : par code, par téléphone (celui de la fiche, le second, ceux
 * des contacts, toujours ramenés à 8 chiffres), puis par nom (sans
 * accents, sans ponctuation, sans majuscules, dans n'importe quel ordre :
 * « Traoré Mariam » et « Mariam Traoré » sont le même nom).
 *
 * Garde aussi les lignes du fichier déjà retenues, pour écarter un client
 * qui y figure deux fois.
 */
final class IndexClients
{
    /** @var array<int, array{code: string, nom: string, telephone: ?string}> */
    private array $fiches = [];

    /** @var array<string, int> */
    private array $parCode = [];

    /** @var array<string, int> */
    private array $parTelephone = [];

    /** @var array<string, list<int>> */
    private array $parNom = [];

    /** @var array<string, int> téléphone => numéro de ligne */
    private array $lignesTelephone = [];

    /** @var array<string, int> nom => numéro de ligne */
    private array $lignesNom = [];

    public static function charger(EntityManagerInterface $em): self
    {
        $index = new self();
        $fiches = $em->createQuery('SELECT c.id, c.code, c.telephone, c.telephone2, c.typeCompte, c.raisonSociale, c.prenom, c.nom FROM '.Clients::class.' c ORDER BY c.id ASC')->getArrayResult();
        foreach ($fiches as $f) {
            $organisation = Clients::TYPE_COMPTE_PARTICULIER !== $f['typeCompte'];
            $nom = $organisation && null !== $f['raisonSociale'] ? $f['raisonSociale'] : trim($f['prenom'].' '.$f['nom']);
            $index->ajouterFiche((int) $f['id'], (string) $f['code'], $nom, [$f['telephone'], $f['telephone2']]);
        }
        foreach ($em->createQuery('SELECT IDENTITY(k.client) AS client, k.telephone FROM '.ContactClient::class.' k WHERE k.telephone IS NOT NULL')->getArrayResult() as $k) {
            $index->ajouterTelephone((int) $k['client'], $k['telephone']);
        }

        return $index;
    }

    /**
     * @param list<?string> $telephones
     */
    public function ajouterFiche(int $id, string $code, string $nom, array $telephones): void
    {
        $principal = null;
        foreach ($telephones as $t) {
            $principal ??= self::huit($t);
        }
        $this->fiches[$id] = ['code' => $code, 'nom' => $nom, 'telephone' => $principal];
        if ('' !== $code) {
            $this->parCode[mb_strtoupper(trim($code))] = $id;
        }
        foreach ($telephones as $t) {
            $this->ajouterTelephone($id, $t);
        }
        $cle = self::cleNom($nom);
        if ('' !== $cle) {
            $this->parNom[$cle][] = $id;
        }
    }

    public function ajouterTelephone(int $id, ?string $telephone): void
    {
        $t = self::huit($telephone);
        if (null !== $t) {
            // La fiche la plus ancienne garde le numéro.
            $this->parTelephone[$t] ??= $id;
        }
    }

    /**
     * La fiche que désigne la ligne : par code, puis par téléphone, puis
     * par nom. « ambigu » : plusieurs fiches portent ce nom.
     *
     * @param list<?string> $telephones
     *
     * @return array{id: int, code: string, nom: string, telephone: ?string, par: string, ambigu: bool}|null
     */
    public function chercher(?string $code, array $telephones, ?string $nom): ?array
    {
        $code = mb_strtoupper(trim((string) $code));
        if ('' !== $code && isset($this->parCode[$code])) {
            return $this->resultat($this->parCode[$code], 'code');
        }
        foreach ($telephones as $t) {
            $t = self::huit($t);
            if (null !== $t && isset($this->parTelephone[$t])) {
                return $this->resultat($this->parTelephone[$t], 'telephone');
            }
        }
        $cle = self::cleNom((string) $nom);
        if ('' !== $cle && isset($this->parNom[$cle])) {
            return $this->resultat($this->parNom[$cle][0], 'nom', \count($this->parNom[$cle]) > 1);
        }

        return null;
    }

    /** Le code désigne-t-il une fiche ? (sinon c'est celui d'un ancien logiciel) */
    public function codeConnu(string $code): bool
    {
        return isset($this->parCode[mb_strtoupper(trim($code))]);
    }

    /**
     * Une ligne précédente du fichier pour le même client : même téléphone
     * ou même nom.
     *
     * @param list<?string> $telephones
     *
     * @return array{ligne: int, par: string}|null
     */
    public function dejaDansFichier(array $telephones, string $nom): ?array
    {
        foreach ($telephones as $t) {
            $t = self::huit($t);
            if (null !== $t && isset($this->lignesTelephone[$t])) {
                return ['ligne' => $this->lignesTelephone[$t], 'par' => 'telephone'];
            }
        }
        $cle = self::cleNom($nom);
        if ('' !== $cle && isset($this->lignesNom[$cle])) {
            return ['ligne' => $this->lignesNom[$cle], 'par' => 'nom'];
        }

        return null;
    }

    /**
     * @param list<?string> $telephones
     */
    public function retenirLigne(int $numero, array $telephones, string $nom): void
    {
        foreach ($telephones as $t) {
            $t = self::huit($t);
            if (null !== $t) {
                $this->lignesTelephone[$t] ??= $numero;
            }
        }
        $cle = self::cleNom($nom);
        if ('' !== $cle) {
            $this->lignesNom[$cle] ??= $numero;
        }
    }

    /** « CLI-000123 Aminata Coulibaly (76 12 34 56) » */
    public static function decrire(array $fiche): string
    {
        $t = $fiche['telephone'] ?? null;

        return trim($fiche['code'].' '.$fiche['nom']).(null !== $t ? ' ('.implode(' ', str_split($t, 2)).')' : '');
    }

    /** « Mariam TRAORÉ » et « Traoré Mariam » : « mariam traore ». */
    public static function cleNom(string $nom): string
    {
        $mots = array_filter(explode(' ', Valeurs::cle($nom)), static fn (string $m): bool => '' !== $m);
        sort($mots);

        return implode(' ', $mots);
    }

    private static function huit(?string $telephone): ?string
    {
        $t = ContactClient::chiffres($telephone);

        return 8 === \strlen($t) ? $t : null;
    }

    /**
     * @return array{id: int, code: string, nom: string, telephone: ?string, par: string, ambigu: bool}
     */
    private function resultat(int $id, string $par, bool $ambigu = false): array
    {
        return ['id' => $id] + ($this->fiches[$id] ?? ['code' => '', 'nom' => '', 'telephone' => null]) + ['par' => $par, 'ambigu' => $ambigu];
    }
}
