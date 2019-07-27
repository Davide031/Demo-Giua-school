<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2019 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2019
 */


namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;


/**
 * ScansioneOraria - entità
 *
 * @ORM\Entity(repositoryClass="AppBundle\Repository\ScansioneOrariaRepository")
 * @ORM\Table(name="gs_scansione_oraria")
 * @ORM\HasLifecycleCallbacks
 */
class ScansioneOraria {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var integer $id Identificativo univoco per la scansione oraria
   *
   * @ORM\Column(type="integer")
   * @ORM\Id
   * @ORM\GeneratedValue(strategy="AUTO")
   */
  private $id;

  /**
   * @var \DateTime $modificato Ultima modifica dei dati
   *
   * @ORM\Column(type="datetime", nullable=false)
   */
  private $modificato;

  /**
   * @var integer $giorno Giorno della settimana [0=domenica, 1=lunedì, ... 6=sabato]
   *
   * @ORM\Column(type="smallint", nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Choice(choices={0,1,2,3,4,5,6}, strict=true, message="field.choice")
   */
  private $giorno;

  /**
   * @var integer $ora Numero dell'ora di lezione [1,2,...]
   *
   * @ORM\Column(type="smallint", nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   */
  private $ora;

  /**
   * @var \DateTime $inizio Inizio dell'ora di lezione
   *
   * @ORM\Column(type="time", nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Time(message="field.time")
   */
  private $inizio;

  /**
   * @var \DateTime $fine Fine dell'ora di lezione
   *
   * @ORM\Column(type="time", nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Time(message="field.time")
   */
  private $fine;

  /**
   * @var integer $durata Durata dell'ora di lezione (in minuti)
   *
   * @ORM\Column(type="smallint", nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   */
  private $durata;

  /**
   * @var Orario $orario Orario a cui appartiene la scansione oraria
   *
   * @ORM\ManyToOne(targetEntity="Orario")
   * @ORM\JoinColumn(nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   */
  private $orario;


  //==================== EVENTI ORM ====================

  /**
   * Simula un trigger onCreate/onUpdate
   *
   * @ORM\PrePersist
   * @ORM\PreUpdate
   */
  public function onChangeTrigger() {
    // aggiorna data/ora di modifica
    $this->modificato = new \DateTime();
  }


  //==================== METODI SETTER/GETTER ====================

  /**
   * Restituisce l'identificativo univoco per la scansione oraria
   *
   * @return integer Identificativo univoco
   */
  public function getId() {
    return $this->id;
  }

  /**
   * Restituisce la data/ora dell'ultima modifica dei dati della scansione oraria
   *
   * @return \DateTime Data/ora dell'ultima modifica
   */
  public function getModificato() {
    return $this->modificato;
  }

  /**
   * Restituisce il giorno della settimana [0=domenica, 1=lunedì, ... 6=sabato]
   *
   * @return integer Giorno della settimana
   */
  public function getGiorno() {
    return $this->giorno;
  }

  /**
   * Modifica il giorno della settimana [0=domenica, 1=lunedì, ... 6=sabato]
   *
   * @param integer $giorno Giorno della settimana
   *
   * @return ScansioneOraria Oggetto ScansioneOraria
   */
  public function setGiorno($giorno) {
    $this->giorno = $giorno;
    return $this;
  }

  /**
   * Restituisce il numero dell'ora di lezione [1,2,...]
   *
   * @return integer Numero dell'ora di lezione
   */
  public function getOra() {
    return $this->ora;
  }

  /**
   * Modifica il numero dell'ora di lezione [1,2,...]
   *
   * @param integer $ora Numero dell'ora di lezione
   *
   * @return ScansioneOraria Oggetto ScansioneOraria
   */
  public function setOra($ora) {
    $this->ora = $ora;
    return $this;
  }

  /**
   * Restituisce l'inizio dell'ora di lezione
   *
   * @return \DateTime Inizio dell'ora di lezione
   */
  public function getInizio() {
    return $this->inizio;
  }

  /**
   * Modifica l'inizio dell'ora di lezione
   *
   * @param \DateTime $inizio Inizio dell'ora di lezione
   *
   * @return ScansioneOraria Oggetto ScansioneOraria
   */
  public function setInizio($inizio) {
    $this->inizio = $inizio;
    return $this;
  }

  /**
   * Restituisce la fine dell'ora di lezione
   *
   * @return \DateTime Fine dell'ora di lezione
   */
  public function getFine() {
    return $this->fine;
  }

  /**
   * Modifica la fine dell'ora di lezione
   *
   * @param \DateTime $fine Fine dell'ora di lezione
   *
   * @return ScansioneOraria Oggetto ScansioneOraria
   */
  public function setFine($fine) {
    $this->fine = $fine;
    return $this;
  }

  /**
   * Restituisce la durata dell'ora di lezione (in minuti)
   *
   * @return integer Durata dell'ora di lezione
   */
  public function getDurata() {
    return $this->durata;
  }

  /**
   * Modifica la durata dell'ora di lezione (in minuti)
   *
   * @param integer $durata Durata dell'ora di lezione
   *
   * @return ScansioneOraria Oggetto ScansioneOraria
   */
  public function setDurata($durata) {
    $this->durata = $durata;
    return $this;
  }

  /**
   * Restituisce l'orario a cui appartiene la scansione oraria
   *
   * @return Orario Orario a cui appartiene la scansione oraria
   */
  public function getOrario() {
    return $this->orario;
  }

  /**
   * Modifica l'orario a cui appartiene la scansione oraria
   *
   * @param Orario $orario Orario a cui appartiene la scansione oraria
   *
   * @return ScansioneOraria Oggetto ScansioneOraria
   */
  public function setOrario(Orario $orario) {
    $this->orario = $orario;
    return $this;
  }


  //==================== METODI DELLA CLASSE ====================

  /**
   * Restituisce l'oggetto rappresentato come testo
   *
   * @return string Oggetto rappresentato come testo
   */
  public function __toString() {
    return $this->giorno.':'.$this->ora;
  }

}

