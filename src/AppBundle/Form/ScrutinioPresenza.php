<?php
/**
 * giua@school
 *
 * Copyright (c) 2017 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017
 */


namespace AppBundle\Form;


/**
 * ScrutinioPresenza - classe di utilità per la gestione delle presenze nello scrutinio
 */
class ScrutinioPresenza {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var integer $docente Identificativo univoco per il docente
   */
  private $docente;

  /**
   * @var bool $presenza Indica se il docente è presente oppure no
   */
  private $presenza;

  /**
   * @var string $sostituto Sostituto del docente in caso di sua assenza
   */
  private $sostituto;


  //==================== METODI SETTER/GETTER ====================

  /**
   * Restituisce l'identificativo univoco per il docente
   *
   * @return integer Identificativo univoco per il docente
   */
  public function getDocente() {
    return $this->docente;
  }

  /**
   * Modifica l'identificativo univoco per il docente
   *
   * @var integer $id Identificativo univoco per il docente
   *
   * @return ScrutinioPresenza Oggetto ScrutinioPresenza
   */
  public function setDocente($docente) {
    $this->docente = $docente;
    return $this;
  }

  /**
   * Restituisce se il docente è presente oppure no
   *
   * @return bool Indica se il docente è presente oppure no
   */
  public function getPresenza() {
    return $this->presenza;
  }

  /**
   * Modifica se il docente è presente oppure no
   *
   * @var bool $presenza Indica se il docente è presente oppure no
   *
   * @return ScrutinioPresenza Oggetto ScrutinioPresenza
   */
  public function setPresenza($presenza) {
    $this->presenza = $presenza;
    return $this;
  }

  /**
   * Restituisce il sostituto del docente in caso di sua assenza
   *
   * @return string Sostituto del docente in caso di sua assenza
   */
  public function getSostituto() {
    return $this->sostituto;
  }

  /**
   * Modifica il sostituto del docente in caso di sua assenza
   *
   * @var string $sostituto Sostituto del docente in caso di sua assenza
   *
   * @return ScrutinioPresenza Oggetto ScrutinioPresenza
   */
  public function setSostituto($sostituto) {
    $this->sostituto = $sostituto;
    return $this;
  }

}

