<?php

namespace App\Tests\Unit\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\DemandeDevis;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Service\Assistant\ContexteAssistant;
use App\Service\Assistant\OutilsAssistant;
use App\Service\Assistant\ReglagesAssistant;
use App\Service\Assistant\SourceEspace;
use App\Service\Assistant\SourceSite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Les outils ne lisent que la fiche du compte connecté (venue de la
 * session), et les montants seulement pour qui paie.
 */
final class OutilsAssistantTest extends TestCase
{
    private Clients $entreprise;
    private Clients $particulier;
    private CompteClient $payeur;
    private CompteClient $demandeur;
    private CompteClient $lecture;
    private CompteClient $fanta;

    /** @var \ArrayObject<int, array<string, mixed>> valeurs des demandes de devis enregistrées */
    private \ArrayObject $demandes;

    private OutilsAssistant $outils;

    protected function setUp(): void
    {
        $this->entreprise = (new Clients())->setTypeClient('B2B')->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setRaisonSociale('Salon Espoir');
        $this->particulier = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER)->setNom('Diarra')->setPrenom('Fanta');

        $this->payeur = (new CompteClient())->setClient($this->entreprise)->setNom('Oumar')->setTelephone('22360697804')->setDroits([CompteClient::DROIT_ADMINISTRATEUR]);
        $this->demandeur = (new CompteClient())->setClient($this->entreprise)->setNom('Mariam')->setTelephone('22366000103')->setDroits([]);
        $this->lecture = (new CompteClient())->setClient($this->entreprise)->setNom('Sidi')->setTelephone('22366000105')->setDroits([CompteClient::DROIT_LECTURE]);
        $this->fanta = (new CompteClient())->setClient($this->particulier)->setNom('Fanta')->setTelephone('22376000001');

        $commandes = [
            spl_object_id($this->entreprise) => [self::commande($this->entreprise, 'W-CMD-00012', 150000)],
            spl_object_id($this->particulier) => [self::commande($this->particulier, 'CMD-00031', 24000)],
        ];
        $this->demandes = new \ArrayObject();

        $espace = new class($commandes, $this->demandes) implements SourceEspace {
            /** @param array<int, list<Commandes>> $commandes */
            public function __construct(private readonly array $commandes, private readonly \ArrayObject $demandes)
            {
            }

            public function commandes(Clients $client): array
            {
                return $this->commandes[spl_object_id($client)] ?? [];
            }

            public function carte(Commandes $commande): array
            {
                return [
                    'commande' => $commande, 'titre' => 'Bâche 300 × 100 cm', 'refuseeEntreprise' => false, 'annulee' => false, 'aValiderEntreprise' => false,
                    'aConfirmer' => false, 'terminee' => false, 'batEnAttente' => false,
                    'courante' => ['libelle' => 'Impression', 'detail' => null],
                    'etapes' => [['libelle' => 'Reçue', 'etat' => 'faite', 'date' => new \DateTimeImmutable('2026-10-10'), 'detail' => null], ['libelle' => 'Impression', 'etat' => 'en_cours', 'date' => null, 'detail' => null]],
                ];
            }

            public function solde(Clients $client): array
            {
                $commandes = array_values(array_filter($this->commandes($client), static fn (Commandes $c): bool => $c->getResteAPayer() > 0));

                return ['total' => (float) array_sum(array_map(static fn (Commandes $c): int => $c->getResteAPayer(), $commandes)), 'enValidation' => 0, 'commandes' => $commandes];
            }

            public function facturesARegler(Clients $client): array
            {
                return [];
            }

            public function produits(Clients $client): array
            {
                return [['nom' => 'Carte de visite '.$client->getRaisonSociale(), 'format' => '85 × 55 mm', 'prix' => '25 000 FCFA', 'batLe' => null]];
            }

            public function paiementEnLigne(): bool
            {
                return true;
            }

            public function enregistrerDemande(array $valeurs, \DateTimeImmutable $le): DemandeDevis
            {
                $this->demandes->append($valeurs);

                return (new DemandeDevis())->setNumero('W-DS-0007');
            }
        };

        $site = $this->createStub(SourceSite::class);
        $site->method('configurations')->willReturn([]);
        $site->method('lien')->willReturnCallback(static fn (string $route, array $p = []): string => '/'.$route.([] === $p ? '' : '/'.implode('/', $p)));

        $reglages = $this->createStub(ReglagesAssistant::class);
        $reglages->method('lienWhatsApp')->willReturnCallback(static fn (string $t): string => 'https://wa.me/22370000000?text='.rawurlencode($t));

        $this->outils = new OutilsAssistant($site, $espace, $reglages, new RateLimiterFactory(['id' => 'essai', 'policy' => 'no_limit'], new InMemoryStorage()));
    }

    private static function commande(Clients $client, string $numero, int $total): Commandes
    {
        return (new Commandes())->setClients($client)->setNumero($numero)->setTotalTtc($total)->setDateCommande(new \DateTimeImmutable('2026-10-10'));
    }

    /** @return array<string, mixed> */
    private function appeler(string $outil, array $entree, CompteClient $compte): array
    {
        $ctx = ContexteAssistant::espace($compte, new \DateTimeImmutable('2026-10-14 10:00'));
        $resultat = $this->outils->executer($outil, $entree, $ctx, AssistantConversation::nouvelle(AssistantConversation::ESPACE, 'claude-opus-5-5', $ctx->maintenant));

        return ['donnees' => $resultat->donnees, 'erreur' => $resultat->erreur, 'actions' => $resultat->actions, 'texte' => $resultat->texte()];
    }

    public function testUnClientNeVoitQueSesCommandes(): void
    {
        $r = $this->appeler('mes_commandes', ['numero' => ''], $this->payeur);
        self::assertSame(['W-CMD-00012'], array_column($r['donnees']['commandes'], 'numero'));
        self::assertStringNotContainsString('CMD-00031', $r['texte']);

        // Le numéro d'une commande d'un autre client ne donne rien.
        $autre = $this->appeler('mes_commandes', ['numero' => 'CMD-00031'], $this->payeur);
        self::assertSame([], $autre['donnees']['commandes']);
        self::assertStringNotContainsString('24', json_encode($autre['donnees']['commandes']) ?: '');

        $fanta = $this->appeler('mes_commandes', ['numero' => '31'], $this->fanta);
        self::assertSame(['CMD-00031'], array_column($fanta['donnees']['commandes'], 'numero'));
        self::assertStringNotContainsString('W-CMD-00012', $fanta['texte']);
        self::assertSame([], $this->appeler('mes_commandes', ['numero' => '12'], $this->fanta)['donnees']['commandes']);
    }

    public function testLesMontantsSeulementPourQuiPaie(): void
    {
        $payeur = $this->appeler('mes_commandes', ['numero' => ''], $this->payeur)['donnees'];
        self::assertArrayHasKey('total_ttc', $payeur['commandes'][0]);
        self::assertArrayHasKey('reste_a_payer', $payeur['commandes'][0]);
        self::assertSame('visibles', $payeur['montants']);

        $demandeur = $this->appeler('mes_commandes', ['numero' => ''], $this->demandeur);
        self::assertArrayNotHasKey('total_ttc', $demandeur['donnees']['commandes'][0]);
        self::assertArrayNotHasKey('reste_a_payer', $demandeur['donnees']['commandes'][0]);
        self::assertStringNotContainsString('FCFA', $demandeur['texte']);
        self::assertSame('Impression', $demandeur['donnees']['commandes'][0]['etape_actuelle'], 'l\'étape reste visible');

        // Reste à payer : refusé au demandeur et à la lecture, donné au payeur et au particulier.
        foreach ([$this->demandeur, $this->lecture] as $compte) {
            $refus = $this->appeler('reste_a_payer', [], $compte);
            self::assertTrue($refus['erreur']);
            self::assertStringNotContainsString('FCFA', $refus['texte']);
        }
        $reste = $this->appeler('reste_a_payer', [], $this->payeur);
        self::assertFalse($reste['erreur']);
        self::assertSame('150 000', str_replace(["\u{A0}", "\u{202F}"], ' ', substr($reste['donnees']['reste_a_payer'], 0, -strlen("\u{A0}FCFA"))));
        self::assertSame('/app_espace_payer', substr($reste['actions'][0]['url'], 0, 17));
        self::assertFalse($this->appeler('reste_a_payer', [], $this->fanta)['erreur'], 'un particulier fait tout');

        // Mes produits : sans prix pour le demandeur, fermé à la lecture.
        self::assertArrayNotHasKey('prix', $this->appeler('mes_produits', [], $this->demandeur)['donnees']['produits'][0]);
        self::assertSame('25 000 FCFA', $this->appeler('mes_produits', [], $this->payeur)['donnees']['produits'][0]['prix']);
        self::assertTrue($this->appeler('mes_produits', [], $this->lecture)['erreur']);
    }

    public function testDemandeDeDevisAuNomDuCompteConnecte(): void
    {
        self::assertTrue($this->appeler('preparer_demande_devis', ['produit' => 0, 'quantite' => 0, 'largeur_cm' => 0, 'hauteur_cm' => 0, 'finitions' => [], 'message' => 'Une enseigne lumineuse'], $this->lecture)['erreur']);
        self::assertCount(0, $this->demandes);

        $r = $this->appeler('preparer_demande_devis', ['produit' => 0, 'quantite' => 0, 'largeur_cm' => 0, 'hauteur_cm' => 0, 'finitions' => [], 'message' => 'Une enseigne lumineuse de 2 m', 'telephone' => '22399999999'], $this->fanta);
        self::assertFalse($r['erreur']);
        self::assertSame('W-DS-0007', $r['donnees']['numero']);
        // Nom et téléphone viennent du compte, jamais de ce que le modèle écrit.
        self::assertSame('Fanta', $this->demandes[0]['nom']);
        self::assertSame('22376000001', $this->demandes[0]['telephone']);
        self::assertStringStartsWith('Demande préparée avec l\'assistant', (string) $this->demandes[0]['message']);
    }

    public function testLeSiteNOuvreAucunOutilDeLEspace(): void
    {
        $ctx = ContexteAssistant::site(new \DateTimeImmutable('2026-10-14 10:00'));
        foreach (['mes_commandes', 'reste_a_payer', 'mes_produits', 'preparer_demande_devis', 'inconnu'] as $outil) {
            $r = $this->outils->executer($outil, [], $ctx, null);
            self::assertTrue($r->erreur, $outil);
            self::assertSame(['erreur' => 'Outil inconnu.'], $r->donnees);
        }
    }

    public function testCatalogueTrouveParLeNomCommercialOuTechnique(): void
    {
        // docs/noms-commerciaux.md : « autocollant » trouve « Vinyle adhésif », et l'inverse.
        $vinyle = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant'));
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche imprimée')->setNomCommercial('Banderole'));
        $configurations = [$vinyle, $bache];

        self::assertSame([$vinyle], OutilsAssistant::chercher($configurations, 'des autocollants'));
        self::assertSame([$vinyle], OutilsAssistant::chercher($configurations, 'vinyle adhesif'));
        self::assertSame([$bache], OutilsAssistant::chercher($configurations, 'banderoles'));
        self::assertSame([$bache], OutilsAssistant::chercher($configurations, 'bache'));
    }

    public function testDefinitionsStrictes(): void
    {
        self::assertSame(OutilsAssistant::OUTILS_SITE, array_column(OutilsAssistant::definitions(AssistantConversation::SITE), 'name'));
        self::assertSame(OutilsAssistant::OUTILS_ESPACE, array_column(OutilsAssistant::definitions(AssistantConversation::ESPACE), 'name'));
        foreach (OutilsAssistant::definitions(AssistantConversation::ESPACE) as $d) {
            self::assertTrue($d['strict'], $d['name']);
            self::assertFalse($d['inputSchema']['additionalProperties'], $d['name']);
            self::assertSame(array_keys((array) $d['inputSchema']['properties']), $d['inputSchema']['required'], $d['name']);
            self::assertArrayHasKey($d['name'], OutilsAssistant::LIBELLES);
        }
        // Aucun champ ne laisse le modèle choisir le client, le compte ou le téléphone.
        $champs = array_merge(...array_map(static fn (array $d): array => array_keys((array) $d['inputSchema']['properties']), OutilsAssistant::definitions(AssistantConversation::ESPACE)));
        foreach ($champs as $champ) {
            self::assertDoesNotMatchRegularExpression('/client|compte|telephone|email|nom|id$/i', $champ);
        }
    }

    public function testFiltreParNumero(): void
    {
        $a = self::commande($this->entreprise, 'W-CMD-00012', 1);
        $b = self::commande($this->entreprise, 'CMD-00120', 1);
        self::assertSame([$a], OutilsAssistant::filtrerParNumero([$a, $b], 'cmd 12'));
        self::assertSame([$b], OutilsAssistant::filtrerParNumero([$a, $b], '120'));
        self::assertSame([$a, $b], OutilsAssistant::filtrerParNumero([$a, $b], ''));
        self::assertSame([], OutilsAssistant::filtrerParNumero([$a, $b], 'abc'));
    }

    public function testMessageWhatsAppDuCompte(): void
    {
        $ctx = ContexteAssistant::espace($this->demandeur, new \DateTimeImmutable('2026-10-14 10:00'));
        $texte = OutilsAssistant::messageWhatsApp("Je veux\nannuler", $ctx, 'W-AS-0003');
        self::assertSame('Bonjour Succès Imprim, je suis Mariam (Salon Espoir), depuis mon espace client (conversation W-AS-0003). Je veux annuler', $texte);
        self::assertSame('Bonjour Succès Imprim, je viens de l\'assistant du site.', OutilsAssistant::messageWhatsApp('', ContexteAssistant::site(new \DateTimeImmutable()), null));
    }

    public function testDroitsDuContexte(): void
    {
        $ctx = ContexteAssistant::espace($this->demandeur, new \DateTimeImmutable());
        self::assertFalse($ctx->voitLesMontants());
        self::assertTrue($ctx->peutEcrire());
        self::assertFalse(ContexteAssistant::espace($this->lecture, new \DateTimeImmutable())->peutEcrire());
        self::assertTrue(ContexteAssistant::espace($this->payeur, new \DateTimeImmutable())->voitLesMontants());
    }
}
