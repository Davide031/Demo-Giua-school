<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2020 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2020
 */


namespace App\Repository;


/**
 * CambioClasse - repository
 */
class CambioClasseRepository extends BaseRepository {

  /**
   * Restituisce la lista dei cambi classe degli alunni secondo i criteri di ricerca indicati
   *
   * @param array $criteri Lista dei criteri di ricerca
   * @param int $pagina Pagina corrente
   *
   * @return array Array associativo con la lista dei dati
   */
  public function cerca($criteri, $pagina=1) {
    // crea query base
    $query = $this->createQueryBuilder('cc')
      ->select('cc AS cambio,a.cognome,a.nome,a.dataNascita,cl.anno,cl.sezione')
      ->join('cc.alunno', 'a')
      ->leftJoin('cc.classe', 'cl')
      ->where('a.nome LIKE :nome AND a.cognome LIKE :cognome and a.abilitato=:abilitato')
      ->orderBy('a.cognome,a.nome,a.dataNascita,cc.inizio', 'ASC')
      ->setParameter('nome', $criteri['nome'].'%')
      ->setParameter('cognome', $criteri['cognome'].'%')
      ->setParameter('abilitato', 1);
    if ($criteri['classe'] > 0) {
      $query->andwhere('cl.id=:classe')->setParameter('classe', $criteri['classe']);
    } elseif ($criteri['classe'] == -1) {
      $query->andwhere('cc.classe IS NULL');
    }
    // crea lista con pagine
    return $this->paginazione($query->getQuery(), $pagina);
  }

}
