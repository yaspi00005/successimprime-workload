<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Entity\Employes;
use App\Entity\User;
use App\Service\FichiersPrives;
use App\Service\Messagerie\AgentsMessagerie;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class AgentsMessagerieTest extends TestCase
{
    /** Photo notée sur la fiche mais absente du disque : la réponse au client ne doit pas échouer. */
    public function testPhotoAbsenteDuDisqueSansErreur(): void
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findOneBy')->willReturn(null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);

        $employe = (new Employes())->setNom('Traoré')->setPrenom('Seydou')->setPhotos('absente.jpg');
        $u = (new User())->setEmploye($employe);

        $agent = (new AgentsMessagerie($em, new FichiersPrives(sys_get_temp_dir().'/si-agents-'.bin2hex(random_bytes(4)))))->pour($u, new \DateTimeImmutable());

        self::assertNull($agent->getPhoto());
    }
}
