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

use Doctrine\ORM\EntityRepository;


/**
 * Configurazione - repository
 */
class ConfigurazioneRepository extends EntityRepository {

  /**
   * Restituisce il valore presente nella configurazione del parametro indicato
   *
   * @param string $nome Nome del parametro da leggere
   * @param mixed $default Valore di default nel caso il parametro non esista
   *
   * @return string|null Valore del parametro letto (o null se parametro non esiste)
   */
  public function getParametro($nome, $default=null) {
    // legge valore parametro
    $parametro = $this->createQueryBuilder('c')
      ->select('c.valore')
      ->where('c.parametro=:nome')
      ->setParameter('nome', $nome)
      ->getQuery()
      ->getOneOrNullResult();
    // restituisce valore
    return ($parametro ? $parametro['valore'] : $default);
  }

  /**
   * Restituisce i parametri e i relativi valori da caricare in sessione
   *
   * @return array Lista dei parametri e dei valori
   */
  public function load() {
    $parametri = $this->createQueryBuilder('c')
      ->select('c.categoria,c.parametro,c.valore')
      ->getQuery()
      ->getArrayResult();
    // restituisce valori
    return $parametri;
  }

}
