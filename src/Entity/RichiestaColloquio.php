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


/**
 * RichiestaColloquio - entità
 *
 * @ORM\Entity(repositoryClass="App\Repository\RichiestaColloquioRepository")
 * @ORM\Table(name="gs_richiesta_colloquio")
 * @ORM\HasLifecycleCallbacks
 */
class RichiestaColloquio {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var integer $id Identificativo univoco per la richiesta del colloquio
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
   * @var \DateTime $data Data del colloquio
   *
   * @ORM\Column(type="date", nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Date(message="field.date")
   */
  private $data;

  /**
   * @var Colloquio $colloquio Colloquio richiesto
   *
   * @ORM\ManyToOne(targetEntity="Colloquio")
   * @ORM\JoinColumn(nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   */
  private $colloquio;

  /**
   * @var Alunno $alunno Alunno al quale si riferisce il colloquio
   *
   * @ORM\ManyToOne(targetEntity="Alunno")
   * @ORM\JoinColumn(nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   */
  private $alunno;

  /**
   * @var string $stato Stato della richiesta del colloquio [R=richiesto dal genitore, A=annullato dal genitore, C=confermato dal docente, N=negato dal docente]
   *
   * @ORM\Column(type="string", length=1, nullable=false)
   *
   * @Assert\NotBlank(message="field.notblank")
   * @Assert\Choice(choices={"R","A","C","N"}, strict=true, message="field.choice")
   */
  private $stato;

  /**
   * @var string $messaggio Messaggio da comunicare relativamente allo stato della richiesta
   *
   * @ORM\Column(type="text", nullable=true)
   */
  private $messaggio;


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
   * Restituisce l'identificativo univoco per la richiesta di colloquio
   *
   * @return integer Identificativo univoco
   */
  public function getId() {
    return $this->id;
  }

  /**
   * Restituisce la data/ora dell'ultima modifica dei dati del colloquio
   *
   * @return \DateTime Data/ora dell'ultima modifica
   */
  public function getModificato() {
    return $this->modificato;
  }

  /**
   * Restituisce la data del colloquio
   *
   * @return \DateTime Data del colloquio
   */
  public function getData() {
    return $this->data;
  }

  /**
   * Modifica la data del colloquio
   *
   * @param \DateTime $data Data del colloquio
   *
   * @return RichiestaColloquio Oggetto RichiestaColloquio
   */
  public function setData($data) {
    $this->data = $data;
    return $this;
  }

  /**
   * Restituisce il colloquio richiesto
   *
   * @return Colloquio Colloquio richiesto
   */
  public function getColloquio() {
    return $this->colloquio;
  }

  /**
   * Modifica il colloquio richiesto
   *
   * @param Colloquio $colloquio Colloquio richiesto
   *
   * @return RichiestaColloquio Oggetto RichiestaColloquio
   */
  public function setColloquio(Colloquio $colloquio) {
    $this->colloquio = $colloquio;
    return $this;
  }

  /**
   * Restituisce l'alunno al quale si riferisce il colloquio
   *
   * @return Alunno Alunno al quale si riferisce il colloquio
   */
  public function getAlunno() {
    return $this->alunno;
  }

  /**
   * Modifica l'alunno al quale si riferisce il colloquio
   *
   * @param Alunno $alunno Alunno al quale si riferisce il colloquio
   *
   * @return RichiestaColloquio Oggetto RichiestaColloquio
   */
  public function setAlunno(Alunno $alunno) {
    $this->alunno = $alunno;
    return $this;
  }

  /**
   * Restituisce lo stato della richiesta del colloquio [R=richiesto dal genitore, A=annullato dal genitore, C=confermato dal docente, N=negato dal docente]
   *
   * @return string Stato della richiesta del colloquio
   */
  public function getStato() {
    return $this->stato;
  }

  /**
   * Modifica lo stato della richiesta del colloquio [R=richiesto dal genitore, A=annullato dal genitore, C=confermato dal docente, N=negato dal docente]
   *
   * @param string $stato Stato della richiesta del colloquio
   *
   * @return RichiestaColloquio Oggetto RichiestaColloquio
   */
  public function setStato($stato) {
    $this->stato = $stato;
    return $this;
  }

  /**
   * Restituisce il messaggio da comunicare relativamente allo stato della richiesta
   *
   * @return string Messaggio da comunicare relativamente allo stato della richiesta
   */
  public function getMessaggio() {
    return $this->messaggio;
  }

  /**
   * Modifica il messaggio da comunicare relativamente allo stato della richiesta
   *
   * @param string $messaggio Messaggio da comunicare relativamente allo stato della richiesta
   *
   * @return RichiestaColloquio Oggetto RichiestaColloquio
   */
  public function setMessaggio($messaggio) {
    $this->messaggio = $messaggio;
    return $this;
  }


  //==================== METODI DELLA CLASSE ====================

  /**
   * Restituisce l'oggetto rappresentato come testo
   *
   * @return string Oggetto rappresentato come testo
   */
  public function __toString() {
    return $this->data->format('d/m/Y').', '.$this->colloquio;
  }

}
