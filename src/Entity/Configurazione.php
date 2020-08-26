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


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;


/**
 * Configurazione - entità
 *
 * @ORM\Entity(repositoryClass="App\Repository\ConfigurazioneRepository")
 * @ORM\Table(name="gs_configurazione")
 * @ORM\HasLifecycleCallbacks
 *
 * @UniqueEntity(fields="parametro", message="field.unique")
 */
class Configurazione {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var integer $id Identificativo univoco per la configurazione
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
   * @var string $categoria Categoria a cui appartiene la configurazione
   *
   * @ORM\Column(type="string", length=32, nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Length(max=32,maxMessage="field.maxlength")
   */
  private $categoria;

  /**
   * @var string $parametro Parametro della configurazione
   *
   * @ORM\Column(type="string", length=64, unique=true, nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Length(max=64,maxMessage="field.maxlength")
   */
  private $parametro;

  /**
  * @var string $descrizione Descrizione dell'utilizzo del parametro
   *
   * @ORM\Column(type="string", length=1024, nullable=true)
   *
   * @Assert\Length(max=1024,maxMessage="field.maxlength")
   */
  private $descrizione;

  /**
   * @var string $valore Valore della configurazione
   *
   * @ORM\Column(type="string", length=255, nullable=false)
   *
   * @Assert\Length(max=255,maxMessage="field.maxlength")
   */
  private $valore;

  /**
  * @var boolean $gestito Indica se il parametro viene gestito da una procedura apposita
   *
   * @ORM\Column(type="boolean", nullable=false)
   */
  private $gestito;


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
   * Restituisce l'identificativo univoco per la materia
   *
   * @return integer Identificativo univoco
   */
  public function getId() {
    return $this->id;
  }

  /**
   * Restituisce la data/ora dell'ultima modifica dei dati della materia
   *
   * @return \DateTime Data/ora dell'ultima modifica
   */
  public function getModificato() {
    return $this->modificato;
  }

  /**
   * Restituisce la categoria a cui appartiene la configurazione
   *
   * @return string Categoria a cui appartiene la configurazione
   */
  public function getCategoria() {
    return $this->categoria;
  }

  /**
   * Modifica la categoria a cui appartiene la configurazione
   *
   * @param string $categoria Categoria a cui appartiene la configurazione
   *
   * @return Configurazione Oggetto Configurazione
   */
  public function setCategoria($categoria) {
    $this->categoria = $categoria;
    return $this;
  }

  /**
   * Restituisce il parametro della configurazione
   *
   * @return string Parametro della configurazione
   */
  public function getParametro() {
    return $this->parametro;
  }

  /**
   * Modifica il parametro della configurazione
   *
   * @param string $parametro Parametro della configurazione
   *
   * @return Configurazione Oggetto Configurazione
   */
  public function setParametro($parametro) {
    $this->parametro = $parametro;
    return $this;
  }

  /**
   * Restituisce la descrizione dell'utilizzo del parametro
   *
   * @return string Descrizione dell'utilizzo del parametro
   */
  public function getDescrizione() {
    return $this->descrizione;
  }

  /**
   * Modifica la descrizione dell'utilizzo del parametro
   *
   * @param string $descrizione Descrizione dell'utilizzo del parametro
   *
   * @return Configurazione Oggetto Configurazione
   */
  public function setDescrizione($descrizione) {
    $this->descrizione = $descrizione;
    return $this;
  }

  /**
   * Restituisce il valore della configurazione
   *
   * @return string Valore della configurazione
   */
  public function getValore() {
    return $this->valore;
  }

  /**
   * Modifica il valore della configurazione
   *
   * @param string $valore Valore della configurazione
   *
   * @return Configurazione Oggetto Configurazione
   */
  public function setValore($valore) {
    $this->valore = $valore;
    return $this;
  }

  /**
   * Restituisce se il parametro viene gestito da una procedura apposita o no
   *
   * @return boolean Indica se il parametro viene gestito da una procedura apposita
   */
  public function getGestito() {
    return $this->gestito;
  }

  /**
   * Modifica se il parametro viene gestito da una procedura apposita o no
   *
   * @param boolean $gestito Indica se il parametro viene gestito da una procedura apposita
   *
   * @return Configurazione Oggetto Configurazione
   */
  public function setGestito($gestito) {
    $this->gestito = $gestito;
    return $this;
  }

  //==================== METODI DELLA CLASSE ====================

  /**
   * Costruttore
   */
  public function __construct() {
    // valori predefiniti
    $this->valore = '';
    $this->gestito = false;
  }

  /**
   * Restituisce l'oggetto rappresentato come testo
   *
   * @return string Oggetto rappresentato come testo
   */
  public function __toString() {
    return $this->parametro.' = '.$this->valore;
  }

}
