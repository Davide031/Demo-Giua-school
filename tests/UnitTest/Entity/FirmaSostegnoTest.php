<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2021 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2021
 */


namespace App\Tests\UnitTest\Entity;

use App\DataFixtures\DocenteFixtures;
use App\DataFixtures\AlunnoFixtures;
use App\DataFixtures\FirmaFixtures;
use App\DataFixtures\FirmaSostegnoFixtures;
use App\Tests\UnitTestCase;


/**
 * Unit test della classe
 */
class FirmaSostegnoTest extends UnitTestCase {

  /**
   * Costruttore
   * Definisce dati per i test.
   *
   */
  public function __construct() {
    parent::__construct();
    // nome dell'entità
    $this->entity = '\App\Entity\FirmaSostegno';
    // campi da testare
    $this->fields = ['lezione', 'docente', 'argomento', 'attivita', 'alunno'];
    // fixture da caricare
    $this->fixtures = [[DocenteFixtures::class, 'encoder'], [AlunnoFixtures::class, 'encoder'],
      FirmaSostegnoFixtures::class];
    // SQL read
    $this->canRead = [
      'gs_firma' => ['id', 'modificato', 'lezione_id', 'docente_id', 'tipo', 'argomento', 'attivita', 'alunno_id'],
      'gs_lezione' => '*',
      'gs_utente' => '*',
      'gs_classe' => '*',
    ];
    // SQL write
    $this->canWrite = [
      'gs_firma' => ['id', 'modificato', 'lezione_id', 'docente_id', 'tipo', 'argomento', 'attivita', 'alunno_id']];
    // SQL exec
    $this->canExecute = ['START TRANSACTION', 'COMMIT'];
  }

  /**
   * Test getter/setter degli attributi, con memorizzazione su database.
   * Sono esclusi gli attributi ereditati.
   */
  public function testAttributi() {
    // carica oggetto esistente
    $existent = $this->em->getRepository($this->entity)->findOneBy([]);
    $this->assertTrue($existent->getId() != null, 'Oggetto esistente');
    // legge lezioni
    $lezioni = $this->em->getRepository('App:Lezione')->findByClasse($this->getReference('classe_2A'));
    $docenti = $this->em->getRepository('App:Docente')->findBy([]);
    $alunni = $this->em->getRepository('App:Alunno')->findByClasse($this->getReference('classe_2A'));
    // crea nuovi oggetti
    for ($i = 0; $i < 3; $i++) {
      $o[$i] = new $this->entity();
      foreach ($this->fields as $field) {
        $data[$i][$field] =
          $field == 'lezione' ? $this->faker->randomElement($lezioni) :
          ($field == 'docente' ? $this->faker->randomElement($docenti) :
          ($field == 'argomento' ? $this->faker->paragraph(2, false) :
          ($field == 'attivita' ? $this->faker->optional(0.3, null)->paragraph(2, false) :
          $this->faker->randomElement($alunni))));
        $o[$i]->{'set'.ucfirst($field)}($data[$i][$field]);
      }
      $this->assertEmpty($o[$i]->getId(), $this->entity.'::getId Pre-inserimento');
      $this->assertEmpty($o[$i]->getModificato(), $this->entity.'::getModificato Pre-inserimento');
      // memorizza su db
      $this->em->persist($o[$i]);
      $this->em->flush();
      $this->assertNotEmpty($o[$i]->getId(), $this->entity.'::getId Post-inserimento');
      $this->assertNotEmpty($o[$i]->getModificato(), $this->entity.'::getModificato Post-inserimento');
      $data[$i]['id'] = $o[$i]->getId();
      $data[$i]['modificato'] = $o[$i]->getModificato();
    }
    // controlla gli attributi
    for ($i = 0; $i < 3; $i++) {
      $created = $this->em->getRepository($this->entity)->find($data[$i]['id']);
      foreach (array_merge(['id', 'modificato'], $this->fields) as $field) {
        $this->assertSame($data[$i][$field], $created->{'get'.ucfirst($field)}(),
          $this->entity.'::get'.ucfirst($field));
      }
    }
    // controlla metodi setId e setModificato
    $rc = new \ReflectionClass($this->entity);
    $this->assertFalse($rc->hasMethod('setId'), 'Esiste metodo '.$this->entity.'::setId');
    $this->assertFalse($rc->hasMethod('setModificato'), 'Esiste metodo '.$this->entity.'::setModificato');
  }

  /**
   * Test altri metodi
   */
  public function testMetodi() {
    // carica oggetto esistente
    $existent = $this->em->getRepository($this->entity)->findOneBy([]);
    // istanza di classe
    $this->assertTrue($existent instanceOf \App\Entity\Firma, $this->entity.'instanceOf Firma');
    $this->assertTrue($existent instanceOf \App\Entity\FirmaSostegno, $this->entity.'instanceOf FirmaSostegno');
    $this->assertTrue(is_a($existent, 'App\Entity\Firma'), $this->entity.'is_a Firma');
    $this->assertTrue(is_a($existent, 'App\Entity\FirmaSostegno'), $this->entity.'is_a FirmaSostegno');
  }

}
