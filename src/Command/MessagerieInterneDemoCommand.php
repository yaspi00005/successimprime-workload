<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Conversation;
use App\Entity\Devis;
use App\Entity\Message;
use App\Entity\PieceJointeMessage;
use App\Entity\User;
use App\Service\FichiersPrives;
use App\Service\Messagerie\NotesVocales;
use App\Service\MessagerieInterne\FichiersEquipe;
use App\Service\MessagerieInterne\Groupes;
use App\Service\MessagerieInterne\MessagesEquipe;
use App\Service\Parametres\Parametres;
use App\Service\PiecesJointesChat;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Messagerie de l'équipe fictive (docs/messagerie-interne.md, maquettes MI01
 * à MI05), lancée par app:demo : les groupes par défaut avec les comptes de
 * démonstration, le fil de l'Atelier de MI01 (fond perdu de CMD-PM0182,
 * fichier du serveur en lien, photo de la presse, vocal, colis de
 * CMD-PM0173), un groupe de projet ouvert (devis des plaques du Salon
 * Espoir), un groupe de projet fermé à la livraison, un groupe d'annonces
 * et les messages privés du responsable d'atelier (demo.ibrahim, qui peut
 * créer des groupes). Les messages passent par le vrai service : mentions,
 * cartes de commande, notifications et non-lus sont ceux de l'application.
 *
 * Dossier racine des clients : s'il n'est pas réglé, la démonstration en
 * prépare un sous var/demo/dossiers-clients (fichiers partagés en lien).
 */
#[AsCommand(name: 'app:messagerie-interne:demo', description: 'Messagerie de l\'équipe fictive, ou l\'enlève avec --purger.')]
final class MessagerieInterneDemoCommand extends Command
{
    /** Ce que la démonstration crée (les messages et groupes partent avec la purge de demo.sql). */
    public const PURGE = [
        'DELETE FROM messages_chat_reaction WHERE message_id >= 900000 OR utilisateur_id >= 900000',
        'DELETE FROM messages_chat_fichier WHERE message_id >= 900000',
        'UPDATE messages_chat SET reponse_a_id = NULL WHERE id >= 900000',
        'DELETE FROM messages_chat WHERE id >= 900000',
        'DELETE FROM conversation_participants WHERE utilisateur_id >= 900000 OR conversation_id IN (SELECT id FROM (SELECT id FROM conversations WHERE id >= 900000 AND par_defaut IS NULL) AS d)',
        'DELETE FROM conversations WHERE id >= 900000 AND par_defaut IS NULL',
    ];

    private const DOSSIER_DEMO = 'var/demo/dossiers-clients';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connexion,
        private readonly Groupes $groupes,
        private readonly MessagesEquipe $messages,
        private readonly FichiersEquipe $fichiersEquipe,
        private readonly FichiersPrives $fichiersPrives,
        private readonly NotesVocales $notesVocales,
        private readonly Parametres $parametres,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève la messagerie fictive au lieu de la créer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement || $this->enLigne) {
            $io->error('Démonstration refusée en production et sur le site en ligne.');

            return Command::FAILURE;
        }
        $this->purger();
        if ($input->getOption('purger')) {
            $io->success('Messagerie de l\'équipe fictive enlevée.');

            return Command::SUCCESS;
        }
        $u = [];
        foreach (['kadiatou' => 900001, 'fanta' => 900002, 'aminata' => 900003, 'mariam' => 900004, 'moussa' => 900005, 'seydou' => 900006, 'aissata' => 900007, 'oumar' => 900008, 'ibrahim' => 900009, 'bakary' => 900010, 'awa' => 900012] as $cle => $id) {
            $user = $this->em->find(User::class, $id);
            if (!$user instanceof User) {
                $io->warning('Comptes de démonstration absents : lancez d\'abord app:demo.');

                return Command::SUCCESS;
            }
            $u[$cle] = $user;
        }
        // Le responsable d'atelier crée des groupes (droit « Créer des groupes »).
        $this->connexion->executeStatement("UPDATE user SET droits_ajoutes = JSON_ARRAY_APPEND(COALESCE(droits_ajoutes, '[]'), '$', 'ROLE_MESSAGERIE_GROUPES') WHERE id = 900009 AND NOT JSON_CONTAINS(COALESCE(droits_ajoutes, '[]'), '\"ROLE_MESSAGERIE_GROUPES\"')");
        // Son métier, comme dans la maquette MI01 (« Chef d'atelier »).
        $this->connexion->executeStatement("UPDATE employes e JOIN user u ON u.employe_id = e.id SET e.fonction = 'Chef d''atelier' WHERE u.id = 900009");
        $this->em->refresh($u['ibrahim']);
        $this->preparerDossiers();

        // Groupes par défaut (créés par la migration) : les membres de démonstration par rôle.
        $this->groupes->creerParDefaut($u['kadiatou']);
        $parDefaut = [];
        foreach (array_keys(Groupes::PAR_DEFAUT) as $cle) {
            $g = $this->em->getRepository(Conversation::class)->findOneBy(['parDefaut' => $cle]);
            if (null === $g) {
                continue;
            }
            $this->groupes->ajouterParRoles($g);
            foreach ($g->getParticipants() as $p) {
                if (null !== $p->getUtilisateur() && \in_array(User::ROLE_ADMIN, $p->getUtilisateur()->getRoles(), true)) {
                    $p->setAdmin(true);
                }
            }
            $parDefaut[$cle] = $g;
        }
        if (isset($parDefaut['atelier'])) {
            $parDefaut['atelier']->ajouterParticipant($u['ibrahim'], true);
        }
        $this->em->flush();
        $aujourdhui = (new \DateTimeImmutable())->setTime(0, 0);
        $hier = $aujourdhui->modify('-1 day');
        $n = 0;

        // 1. Général : la vie de l'imprimerie.
        if (isset($parDefaut['general'])) {
            $g = $parDefaut['general'];
            $this->dire($g, $u['kadiatou'], 'Bonjour à tous : réunion d\'équipe vendredi à 8 h, avant l\'ouverture. Ordre du jour : la rentrée scolaire et le planning des congés.', $hier->setTime(8, 5));
            $this->dire($g, $u['aminata'], 'Bien noté. Je ferme la caisse à 7 h 55 pour venir.', $hier->setTime(8, 12));
            $this->dire($g, $u['bakary'], 'Je passe prendre CMD-PM0173 à 8 h demain.', $aujourdhui->setTime(9, 40));
            ++$n;
        }

        // 2. Atelier (MI01) : fond perdu de CMD-PM0182, fichier du serveur en lien, photo de la presse, vocal, colis.
        if (isset($parDefaut['atelier'])) {
            $g = $parDefaut['atelier'];
            $planning = $this->piece($this->xlsx('Planning semaine 43', [['Jour', 'Machine', 'Opérateur', 'Travaux'], ['Lundi', 'Traceur grand format', 'Oumar', 'Bâches, kakémonos'], ['Mardi', 'Presse de sublimation', 'Awa', 'T-shirts, mugs'], ['Mercredi', 'Numérique A3', 'Oumar', 'Cartes, flyers'], ['Règle', 'Sécurité', 'Tous', 'Gants et lunettes au massicot ; machine coupée avant nettoyage']]), 'Planning semaine 43.xlsx');
            $epingle = $this->dire($g, $u['ibrahim'], 'Planning de la semaine 43 et règles de sécurité des machines : à lire avant lundi.', $hier->setTime(17, 30), [$planning]);
            $this->messages->epingler($epingle, $u['ibrahim']);
            $m = $this->dire($g, $u['oumar'], '@Seydou le fichier de CMD-PM0182 n\'a pas de fond perdu, je te l\'ai renvoyé.', $aujourdhui->setTime(9, 52), [], [(int) $u['seydou']->getId()]);
            $client = $this->em->find(Clients::class, 900008);
            $liens = [];
            if (null !== $client) {
                try {
                    $liens[] = $this->fichiersEquipe->lienServeur($client, '04 Prêts à imprimer/kakemonos-etoiles_600x1600_v2.pdf')->setCommande($this->em->getRepository(Commandes::class)->findOneBy(['numero' => 'CMD-PM0182']));
                } catch (\DomainException) {
                }
            }
            $r = $this->dire($g, $u['seydou'], 'Corrigé, avec 10 mm de fond perdu. C\'est dans « 04 Prêts à imprimer ».', $aujourdhui->setTime(10, 5), $liens, [], $m);
            $this->messages->reagir($r, $u['oumar'], '👍');
            $this->messages->reagir($r, $u['ibrahim'], '👍');
            $photo = $this->piece($this->photoPresse(), 'IMG_0912.jpg');
            $p = $this->dire($g, $u['ibrahim'], 'Résistance de la presse de sublimation HS. Technicien demain 9 h.', $aujourdhui->setTime(10, 12), [$photo]);
            $this->messages->reagir($p, $u['awa'], '😮');
            $vocale = $this->vocale();
            if (null !== $vocale) {
                $this->dire($g, $u['awa'], '', $aujourdhui->setTime(10, 20), [], [], null, $vocale);
            }
            $b = $this->dire($g, $u['bakary'], 'Je passe prendre CMD-PM0173 à 8 h demain. @Oumar tu peux préparer les 3 colis ?', $aujourdhui->setTime(10, 30));
            $this->messages->reagir($b, $u['oumar'], '👍');
            ++$n;
        }

        // 3. Prépresse : BAT et versions.
        if (isset($parDefaut['prepresse'])) {
            $g = $parDefaut['prepresse'];
            $this->dire($g, $u['aissata'], 'Le BAT des cartes de visite de M. Sow est prêt, je l\'envoie au client ?', $hier->setTime(15, 10));
            $bat = $this->piece($this->pdf('BAT kakemonos Ecole Les Etoiles v2'), 'BAT_kakemonos_v2.pdf');
            $this->dire($g, $u['seydou'], 'Oui, vas-y. Et voici la v2 des kakémonos de CMD-PM0182, envoyée au client.', $hier->setTime(15, 26), [$bat]);
            ++$n;
        }

        // 4. Caisse et finances (montants visibles seulement avec le droit Paiements sur la carte).
        if (isset($parDefaut['caisse'])) {
            $g = $parDefaut['caisse'];
            $this->dire($g, $u['aminata'], 'Clôture faite : la caisse est juste. Il reste un chèque à déposer demain matin.', $hier->setTime(18, 20));
            $this->dire($g, $u['fanta'], 'Merci Aminata. @Mariam le client de CMD-PM0177 doit encore son solde, tu peux le relancer ?', $aujourdhui->setTime(9, 15));
            ++$n;
        }

        // 5. Projet ouvert : devis des plaques du Salon Espoir (fermeture automatique à la livraison, fichiers rangés dans le dossier du client).
        $devis = $this->em->find(Devis::class, 900646);
        if (null !== $devis) {
            $projet = $this->groupes->creer($u['ibrahim'], [
                'nom' => 'Projet Plaques Salon Espoir', 'type' => Conversation::PROJET, 'description' => 'plaques des 20 chambres', 'devis' => $devis,
                'fermetureAuto' => true, 'fichiersPourTous' => true, 'rangerDossierClient' => true,
                'membres' => [$u['moussa'], $u['seydou'], $u['mariam'], $u['oumar']],
            ]);
            $this->dater($projet->getMessages()->last() ?: null, $hier->setTime(11, 0));
            $this->dire($projet, $u['mariam'], 'La cliente demande si la pose est comprise : j\'ai répondu oui, comme dans le devis.', $hier->setTime(15, 50));
            $this->dire($projet, $u['seydou'], 'Je prépare la maquette des plaques dès que le devis est accepté.', $hier->setTime(16, 2));
            $this->dire($projet, $u['moussa'], 'Client OK pour les totems aussi, on attend l\'accord de M. Guindo.', $aujourdhui->setTime(10, 45));
            ++$n;
        }

        // 6. Projet fermé tout seul : la commande des tote bags est livrée.
        $livree = $this->em->find(Commandes::class, 900771);
        if (null !== $livree) {
            $ferme = $this->groupes->creer($u['ibrahim'], [
                'nom' => 'Projet Tote bags Salon Espoir', 'type' => Conversation::PROJET, 'commande' => $livree,
                'fermetureAuto' => true, 'membres' => [$u['awa'], $u['bakary'], $u['aminata']],
            ]);
            $debut = (new \DateTimeImmutable('-12 days'))->setTime(9, 0);
            $this->dater($ferme->getMessages()->last() ?: null, $debut);
            $this->dire($ferme, $u['awa'], 'Les 60 tote bags sont imprimés, je les sèche cette nuit.', $debut->modify('+2 days')->setTime(17, 10));
            $this->dire($ferme, $u['bakary'], 'Livrés au salon, signature reçue.', $debut->modify('+4 days')->setTime(11, 30));
            $this->groupes->fermerLivres([(int) $livree->getId()], $debut->modify('+4 days')->setTime(11, 31));
            ++$n;
        }

        // 7. Annonces : seuls les responsables écrivent.
        $annonces = $this->groupes->creer($u['kadiatou'], [
            'nom' => 'Annonces', 'type' => Conversation::ANNONCES, 'description' => 'informations de la direction',
            'roles' => ['ROLE_CAISSE_COMMANDE', 'ROLE_GRAPHISTE', 'ROLE_PRODUCTION', 'ROLE_LIVREUR'], 'couleur' => 'violet',
        ]);
        $this->dater($annonces->getMessages()->last() ?: null, (new \DateTimeImmutable('-3 days'))->setTime(8, 0));
        $this->dire($annonces, $u['kadiatou'], 'L\'imprimerie sera fermée le jour de la fête de l\'indépendance. Les commandes promises ce jour-là passent au lendemain.', (new \DateTimeImmutable('-3 days'))->setTime(8, 2));
        ++$n;

        // 8. Messages privés du responsable d'atelier (jamais lus par la Direction).
        $prives = [
            [$u['seydou'], [[$u['seydou'], 'Tu peux regarder la v2 des kakémonos avant 11 h ?', $aujourdhui->setTime(9, 58)], [$u['ibrahim'], 'Oui, je passe au prépresse.', $aujourdhui->setTime(10, 1)]]],
            [$u['oumar'], [[$u['ibrahim'], 'Le rouleau de bâche 510 g arrive à 14 h, garde le traceur libre.', $aujourdhui->setTime(8, 40)], [$u['oumar'], 'D\'accord chef.', $aujourdhui->setTime(8, 44)]]],
            [$u['fanta'], [[$u['fanta'], 'Pour la facture du technicien de la presse, tu me donnes le devis signé ?', $aujourdhui->setTime(11, 5)]]],
            [$u['bakary'], [[$u['ibrahim'], 'Les 3 colis de CMD-PM0173 sont au comptoir.', $hier->setTime(17, 40)], [$u['bakary'], 'Merci, je les prends demain 8 h.', $hier->setTime(17, 52)]]],
        ];
        foreach ($prives as [$autre, $lignes]) {
            $c = $this->groupes->prive($u['ibrahim'], $autre);
            foreach ($lignes as [$auteur, $texte, $le]) {
                $this->dire($c, $auteur, $texte, $le);
            }
            ++$n;
        }
        $this->em->flush();

        // Non-lus : tout est lu, sauf pour le responsable d'atelier (Atelier, projet, message de Fanta) et la Direction (Général).
        $this->connexion->executeStatement('UPDATE conversation_participants p SET p.dernier_lu_id = COALESCE((SELECT MAX(m.id) FROM messages_chat m WHERE m.conversation_id = p.conversation_id), 0), p.date_derniere_lecture = NOW() WHERE p.utilisateur_id >= 900000');
        $nonLus = [];
        if (isset($parDefaut['atelier'])) {
            $nonLus[] = [900009, (int) $parDefaut['atelier']->getId(), 3];
        }
        if (isset($parDefaut['general'])) {
            $nonLus[] = [900001, (int) $parDefaut['general']->getId(), 1];
        }
        if (isset($projet)) {
            $nonLus[] = [900009, (int) $projet->getId(), 1];
        }
        $fanta = $this->groupes->prive($u['ibrahim'], $u['fanta']);
        $nonLus[] = [900009, (int) $fanta->getId(), 1];
        foreach ($nonLus as [$qui, $fil, $combien]) {
            $ids = $this->connexion->fetchFirstColumn('SELECT id FROM messages_chat WHERE conversation_id = ? AND genre = ? ORDER BY id DESC LIMIT '.($combien + 1), [$fil, Message::TEXTE]);
            $this->connexion->executeStatement('UPDATE conversation_participants SET dernier_lu_id = ? WHERE conversation_id = ? AND utilisateur_id = ?', [(int) ($ids[$combien] ?? 0), $fil, $qui]);
        }
        // Notifications : lues, sauf le message privé de Fanta pour le responsable d'atelier.
        $this->connexion->executeStatement("UPDATE notifications SET lue = 1 WHERE destinataire_id >= 900000 AND route = 'app_messagerie_interne_voir'");
        $this->connexion->executeStatement("UPDATE notifications SET lue = 0 WHERE destinataire_id = 900009 AND route = 'app_messagerie_interne_voir' AND JSON_UNQUOTE(JSON_EXTRACT(route_parametres, '$.jeton')) = ?", [$fanta->getJeton()]);

        $io->success(\sprintf('Messagerie de l\'équipe fictive : %d fils remplis. Connexion conseillée : demo.ibrahim (responsable d\'atelier) ou demo.kadiatou (direction).', $n));

        return Command::SUCCESS;
    }

    /**
     * Un message envoyé par le vrai service, puis daté dans le passé.
     *
     * @param list<PieceJointeMessage> $pieces
     * @param list<int>                $mentions
     */
    private function dire(Conversation $c, User $auteur, string $texte, \DateTimeImmutable $le, array $pieces = [], array $mentions = [], ?Message $reponse = null, ?array $vocale = null): Message
    {
        $m = $this->messages->envoyer($c, $auteur, $texte, $reponse, $mentions, $pieces, $vocale);
        $this->dater($m, $le);
        $this->em->flush();

        return $m;
    }

    private function dater(?Message $m, \DateTimeImmutable $le): void
    {
        if (null === $m) {
            return;
        }
        $m->setDateEnvoi($le);
        foreach ($m->getPieces() as $p) {
            $p->setCreeLe($le);
        }
        // Les messages sont écrits dans l'ordre : le dernier daté est le plus récent du fil.
        $m->getConversation()?->setDateDernierMessage($le);
    }

    private function piece(string $source, string $nom): PieceJointeMessage
    {
        try {
            return $this->fichiersEquipe->rangerFichier($source, $nom);
        } finally {
            @unlink($source);
        }
    }

    /** Enlève les fichiers rangés par la démonstration, puis ses lignes. */
    private function purger(): void
    {
        foreach ($this->connexion->fetchAllAssociative('SELECT stockage, miniature FROM messages_chat_fichier WHERE message_id >= 900000') as $f) {
            foreach ([$f['stockage'], $f['miniature']] as $nom) {
                $chemin = null === $nom ? null : $this->fichiersPrives->cheminDans(PiecesJointesChat::DOSSIER, (string) $nom);
                if (null !== $chemin) {
                    @unlink($chemin);
                }
            }
        }
        foreach ($this->connexion->fetchFirstColumn('SELECT vocale_nom FROM messages_chat WHERE id >= 900000 AND vocale_nom IS NOT NULL') as $nom) {
            $this->notesVocales->retirer(['nom' => (string) $nom]);
        }
        $this->connexion->executeStatement("DELETE FROM notifications WHERE route = 'app_messagerie_interne_voir' AND destinataire_id >= 900000");
        foreach (self::PURGE as $sql) {
            $this->connexion->executeStatement($sql);
        }
        $this->em->clear();
    }

    /**
     * Dossier racine des clients de démonstration (fichiers partagés en lien,
     * MI03), seulement si le réglage est vide : un vrai réglage n'est jamais touché.
     */
    private function preparerDossiers(): void
    {
        $racine = $this->projet.'/'.self::DOSSIER_DEMO;
        $reglage = trim((string) $this->parametres->valeur('produits_clients', 'dossier_racine'));
        if ('' !== $reglage && $reglage !== $racine) {
            return;
        }
        $fichiers = [
            'DEMO-0008 École fondamentale de Missabougou/04 Prêts à imprimer/kakemonos-etoiles_600x1600_v2.pdf' => $this->contenuPdf('Kakemonos Ecole Les Etoiles 600x1600 v2'),
            'DEMO-0008 École fondamentale de Missabougou/01 Maquettes/kakemonos-etoiles_sources.psd' => "8BPS\x00\x01".str_repeat("\x00", 200),
            'DEMO-0008 École fondamentale de Missabougou/02 Fichiers reçus/logo-ecole.png' => $this->contenuPhoto(400, 400, [30, 80, 160]),
            'DEMO-0002 Salon Espoir/01 Maquettes/plaques-chambres_v1.pdf' => $this->contenuPdf('Plaques des chambres v1'),
            'DEMO-0002 Salon Espoir/02 Fichiers reçus/charte-salon-espoir.pdf' => $this->contenuPdf('Charte Salon Espoir'),
        ];
        foreach ($fichiers as $relatif => $contenu) {
            $chemin = $racine.'/'.$relatif;
            if (!is_dir(\dirname($chemin))) {
                mkdir(\dirname($chemin), 0775, true);
            }
            if (!is_file($chemin)) {
                file_put_contents($chemin, $contenu);
            }
        }
        if ('' === $reglage) {
            $this->parametres->enregistrer('produits_clients', ['dossier_racine' => $racine], null);
        }
    }

    /** Photo de la presse (MI01) : une machine grise, la résistance entourée en rouge. */
    private function photoPresse(): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'mi_demo_');
        file_put_contents($fichier, $this->contenuPhoto(1200, 900, [60, 64, 72], true));

        return $fichier;
    }

    /** @param array{int, int, int} $fond */
    private function contenuPhoto(int $l, int $h, array $fond, bool $machine = false): string
    {
        $img = imagecreatetruecolor($l, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, ...$fond));
        if ($machine) {
            imagefilledrectangle($img, (int) ($l * .18), (int) ($h * .22), (int) ($l * .82), (int) ($h * .62), (int) imagecolorallocate($img, 170, 174, 182));
            imagefilledrectangle($img, (int) ($l * .28), (int) ($h * .62), (int) ($l * .72), (int) ($h * .92), (int) imagecolorallocate($img, 95, 99, 108));
            imagesetthickness($img, 10);
            imageellipse($img, (int) ($l * .5), (int) ($h * .42), (int) ($l * .2), (int) ($h * .14), (int) imagecolorallocate($img, 220, 30, 40));
        } else {
            imagefilledellipse($img, (int) ($l / 2), (int) ($h / 2), (int) ($l * .7), (int) ($h * .7), (int) imagecolorallocate($img, 245, 200, 40));
        }
        ob_start();
        imagejpeg($img, null, 82);

        return (string) ob_get_clean();
    }

    private function pdf(string $titre): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'mi_demo_');
        file_put_contents($fichier, $this->contenuPdf($titre));

        return $fichier;
    }

    /** Petit PDF d'une page avec un titre (démonstration). */
    private function contenuPdf(string $titre): string
    {
        $flux = 'BT /F1 24 Tf 60 760 Td ('.addcslashes($titre, '()\\').') Tj ET';
        $objets = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.\strlen($flux)." >>\nstream\n".$flux."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $positions = [];
        foreach ($objets as $i => $objet) {
            $positions[] = \strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$objet."\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 ".(\count($objets) + 1)."\n0000000000 65535 f \n";
        foreach ($positions as $p) {
            $pdf .= \sprintf("%010d 00000 n \n", $p);
        }

        return $pdf.'trailer << /Size '.(\count($objets) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** @param list<list<string>> $lignes */
    private function xlsx(string $titre, array $lignes): string
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet()->setTitle(mb_substr($titre, 0, 31));
        $feuille->fromArray($lignes);
        $fichier = tempnam(sys_get_temp_dir(), 'mi_demo_');
        (new Xlsx($classeur))->save($fichier);

        return $fichier;
    }

    /** @return array{nom: string, type: string, taille: int, duree: int, empreinte: string}|null */
    private function vocale(): ?array
    {
        $contenu = @file_get_contents($this->projet.'/donnees-demo/note-vocale-demo.ogg');

        return false === $contenu ? null : $this->notesVocales->ranger($contenu);
    }
}
