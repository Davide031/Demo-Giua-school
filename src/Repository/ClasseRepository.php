<?php
/*
 * SPDX-FileCopyrightText: 2017 I.I.S. Michele Giua - Cagliari - Assemini
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace App\Repository;

use App\Entity\GruppoClasse;


/**
 * Classe - repository
 *
 * @author Antonello Dessì
 */
class ClasseRepository extends BaseRepository {

  /**
   * Restituisce la lista degli ID di classe corretti o l'errore nell'apposito parametro.
   *
   * @param array $sedi Lista di ID delle sedi
   * @param array $lista Lista di ID delle classi
   * @param bool $errore Viene impostato a vero se è presente un errore
   *
   * @return array Lista degli ID delle classi che risultano corretti
   */
  public function controllaClassi($sedi, $lista, &$errore) {
    // legge classi valide
    $classi = $this->createQueryBuilder('c')
      ->select('c.id')
      ->where('c.id IN (:lista) AND c.sede IN (:sedi)')
      ->setParameters(['lista' => $lista, 'sedi' => $sedi])
      ->getQuery()
      ->getArrayResult();
    $lista_classi = array_column($classi, 'id');
    $errore = (count($lista) != count($lista_classi));
    // restituisce classi valide
    return $lista_classi;
  }

  /**
   * Restituisce la rappresentazione testuale della lista delle classi.
   *
   * @param array $lista Lista di ID delle classi
   *
   * @return string Lista delle classi
   */
  public function listaClassi($lista) {
    // legge classi valide
    $classi = $this->createQueryBuilder('c')
      ->select("CONCAT(c.anno,'ª ',c.sezione) AS nome")
      ->where('c.id IN (:lista)')
      ->setParameters(['lista' => $lista])
      ->orderBy('c.sezione,c.anno')
      ->getQuery()
      ->getArrayResult();
    $lista_classi = array_column($classi, 'nome');
    // restituisce lista
    return implode(', ', $lista_classi);
  }

  /**
   * Restituisce le classi per le sedi e il filtro indicato
   *
   * @param array $sedi Sedi di servizio (lista ID di Sede)
   * @param array|null $filtro Lista di ID per il filtro classi o null se nessun filtro
   *
   * @return array Lista di ID delle classi
   */
  public function getIdClasse($sedi, $filtro) {
    $classi = $this->createQueryBuilder('c')
      ->select('DISTINCT c.id')
      ->where('c.sede IN (:sedi)')
      ->setParameters(['sedi' => $sedi]);
    if ($filtro) {
      // filtro classi
      $classi
        ->andWhere('c.id IN (:classi)')->setParameter('classi', $filtro);
    }
    $classi = $classi
      ->getQuery()
      ->getArrayResult();
    // restituisce la lista degli ID
    return array_column($classi, 'id');
  }

  /**
   * Restituisce la lista delle classi con coordinatori secondo i criteri di ricerca indicati
   *
   * @param array $criteri Lista dei criteri di ricerca
   * @param int $pagina Pagina corrente
   *
   * @return array Array associativo con i risultati della ricerca
   */
  public function cercaCoordinatori($criteri, $pagina=1) {
    // crea query
    $query = $this->createQueryBuilder('c')
      ->join('c.coordinatore', 'd')
      ->join('c.sede', 's')
      ->where('d.nome LIKE :nome AND d.cognome LIKE :cognome AND d.abilitato=:abilitato')
      ->orderBy('s.ordinamento,c.anno,c.sezione', 'ASC')
      ->setParameter('nome', $criteri['nome'].'%')
      ->setParameter('cognome', $criteri['cognome'].'%')
      ->setParameter('abilitato', 1);
    if ($criteri['classe'] > 0) {
      $query
        ->andWhere('c.id=:classe')
        ->setParameter('classe', $criteri['classe']);
    }
    // crea lista con pagine
    return $this->paginazione($query->getQuery(), $pagina);
  }

  /**
   * Restituisce la lista delle classi con segretari secondo i criteri di ricerca indicati
   *
   * @param array $criteri Lista dei criteri di ricerca
   * @param int $pagina Pagina corrente
   *
   * @return array Array associativo con i risultati della ricerca
   */
  public function cercaSegretari($criteri, $pagina=1) {
    // crea query
    $query = $this->createQueryBuilder('c')
      ->join('c.segretario', 'd')
      ->join('c.sede', 's')
      ->where('d.nome LIKE :nome AND d.cognome LIKE :cognome AND d.abilitato=:abilitato')
      ->orderBy('s.ordinamento,c.anno,c.sezione', 'ASC')
      ->setParameter('nome', $criteri['nome'].'%')
      ->setParameter('cognome', $criteri['cognome'].'%')
      ->setParameter('abilitato', 1);
    if ($criteri['classe'] > 0) {
      $query
        ->andWhere('c.id=:classe')
        ->setParameter('classe', $criteri['classe']);
    }
    // crea lista con pagine
    return $this->paginazione($query->getQuery(), $pagina);
  }

  /**
   * Restituisce le classi per le sedi e il filtro indicato relativo agli utenti genitori
   *
   * @param array $sedi Sedi di servizio (lista ID di Sede)
   * @param array|null $filtro Lista di ID per il filtro classi o null se nessun filtro
   *
   * @return array Lista di ID delle classi
   */
  public function getIdClasseGenitori($sedi, $filtro) {
    $classi = $this->createQueryBuilder('c')
      ->select('DISTINCT c.id')
      ->where('c.sede IN (:sedi)')
      ->setParameters(['sedi' => $sedi]);
    if ($filtro) {
      // filtro genitori
      $classi
        ->join('App\Entity\Alunno', 'a', 'WITH', 'a.classe=c.id AND a.abilitato=:abilitato')
        ->join('App\Entity\Genitore', 'g', 'WITH', 'g.alunno=a.id AND g.abilitato=:abilitato')
        ->andWhere('g.id IN (:lista)')
        ->setParameter('lista', $filtro)
        ->setParameter('abilitato', 1);
    }
    $classi = $classi
      ->getQuery()
      ->getArrayResult();
    // restituisce la lista degli ID
    return array_column($classi, 'id');
  }

  /**
   * Restituisce le classi per le sedi e il filtro indicato relativo agli utenti alunni
   *
   * @param array $sedi Sedi di servizio (lista ID di Sede)
   * @param array|null $filtro Lista di ID per il filtro classi o null se nessun filtro
   *
   * @return array Lista di ID delle classi
   */
  public function getIdClasseAlunni($sedi, $filtro) {
    $classi = $this->createQueryBuilder('c')
      ->select('DISTINCT c.id')
      ->where('c.sede IN (:sedi)')
      ->setParameters(['sedi' => $sedi]);
    if ($filtro) {
      // filtro alunni
      $classi
        ->join('App\Entity\Alunno', 'a', 'WITH', 'a.classe=c.id AND a.abilitato=:abilitato')
        ->andWhere('a.id IN (:lista)')
        ->setParameter('lista', $filtro)
        ->setParameter('abilitato', 1);
    }
    $classi = $classi
      ->getQuery()
      ->getArrayResult();
    // restituisce la lista degli ID
    return array_column($classi, 'id');
  }

  /**
   * Restituisce la lista ordinata delle classi
   *
   * @param int $pagina Pagina corrente
   *
   * @return array Array associativo con la lista dei dati
   */
  public function cerca($pagina=1) {
    // crea query base
    $query = $this->createQueryBuilder('c')
      ->join('c.sede', 's')
      ->orderBy('s.ordinamento,c.sezione,c.anno', 'ASC');
    // crea lista con pagine
    return $this->paginazione($query->getQuery(), $pagina);
  }

  /**
   * Restituisce le classi per le sedi, i destinatari e il filtro rappresentanti indicato
   *
   * @param array $sedi Sedi di servizio (lista ID di Sede)
   * @param array $destinatari Lista dei destinatari (ruolo utenti)
   * @param array|null $filtro Lista del tipo di rappresentanti
   *
   * @return array Lista di ID delle classi
   */
  public function getIdClasseRappresentanti($sedi, $destinatari, $filtro) {
    $classiId = [];
    // classi per gli alunni rappresentanti
    if ($filtro && in_array('A', $destinatari, true)) {
      // filtro alunni
      $classi = $this->createQueryBuilder('c')
        ->select('DISTINCT c.id')
        ->where('c.sede IN (:sedi)')
        ->join('App\Entity\Alunno', 'a', 'WITH', 'a.classe=c.id AND a.abilitato=:abilitato AND a.rappresentante IN (:lista)')
        ->setParameters(['sedi' => $sedi, 'abilitato' => 1, 'lista' => $filtro])
        ->getQuery()
        ->getArrayResult();
      $classiId = array_column($classi, 'id');
    }
    // classi per i genitori rappresentanti
    if ($filtro && in_array('G', $destinatari, true)) {
      // filtro genitori
      $classi = $this->createQueryBuilder('c')
        ->select('DISTINCT c.id')
        ->where('c.sede IN (:sedi)')
        ->join('App\Entity\Genitore', 'g', 'WITH', 'g.abilitato=:abilitato AND g.rappresentante IN (:lista)')
        ->join('App\Entity\Alunno', 'a', 'WITH', 'g.alunno=a.id AND a.classe=c.id AND a.abilitato=:abilitato')
        ->setParameters(['sedi' => $sedi, 'abilitato' => 1, 'lista' => $filtro])
        ->getQuery()
        ->getArrayResult();
      $classiId = array_unique(array_merge($classiId, array_column($classi, 'id')));
    }
    // restituisce la lista degli ID
    return $classiId;
  }

  /**
   * Restituisce la lista delle classi/gruppi, predisposto per le opzioni dei form
   *
   * @param int|null $sede Identificativo della sede per filtrare le classi sulla sede indicata, nullo per qualsiasi sede
   *
   * @return array Array associativo predisposto per le opzioni dei form
   */
  public function opzioni(?int $sede = null): array {
    // inizializza
    $dati = [];
    // // legge classi
    // $classi = $this->createQueryBuilder('c')
    //   ->join('c.sede', 's');
    // if ($sede) {
    //   $classi = $classi->where('c.sede = :sede')->setParameter('sede', $sede);
    // }
    // $classi = $classi
    //   ->orderBy('s.ordinamento,c.anno,c.sezione')
    //   ->getQuery()
    //   ->getResult();
    // // imposta opzioni
    // foreach ($classi as $classe) {
    //   $gruppo = '';
    //   if ($classe instanceof GruppoClasse) {
    //     $gruppo = '-'.$classe->getNome();
    //   }
    //   $dati[$classe->getSede()->getNomeBreve()][$classe->getAnno().$classe->getSezione().$gruppo] = $classe;
    // }
    // restituisce lista opzioni
    return $dati;
  }

}
