<?php

namespace App\Entity;

/**
 * Donnée rattachée à une société (docs/societes.md) : les documents qui
 * touchent à l'argent ou au stock, et les entreprises émettrices.
 *
 * La société est posée à l'enregistrement par RattachementSociete, selon
 * la règle de SocieteCourante ; personne ne la saisit.
 */
interface AppartientASociete
{
    public function getSociete(): ?Societe;

    public function setSociete(Societe $societe): static;
}
