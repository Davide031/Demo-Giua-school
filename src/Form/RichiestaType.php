<?php
/*
 * SPDX-FileCopyrightText: 2017 I.I.S. Michele Giua - Cagliari - Assemini
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


/**
 * RichiestaType - form per la classe Richiesta
 *
 * @author Antonello Dessì
 */
class RichiestaType extends AbstractType {

  /**
   * Crea il form
   *
   * @param FormBuilderInterface $builder Gestore per la creazione del form
   * @param array $options Lista di opzioni per il form
   */
  public function buildForm(FormBuilderInterface $builder, array $options) {
    // aggiunge campi al form
    if (!$options['dati'][1]) {
      // richiesta multipla: aggiunge data
      $builder->add('data', DateType::class, array('label' => false,
        'attr' => ['class' => 'gs-mb-2'],
        'widget' => 'single_text',
        'required' => true));
    }
    foreach ($options['dati'][0] as $nome => $campo) {
      switch ($campo[0]) {
        case 'string':
          $builder->add($nome, TextType::class, array('label' => false,
            'attr' => ['class' => 'gs-mb-2'],
            'required' => $campo[1]));
          break;
        case 'text':
          $builder->add($nome, TextareaType::class, array('label' => false,
            'attr' => ['style' => 'width:96%; margin-left:2%; margin-right:2%;', 'class' => 'gs-mb-2', 'rows' => 3],
            'required' => $campo[1]));
          break;
        case 'int':
          $builder->add($nome, IntegerType::class, array('label' => false,
            'attr' => ['class' => 'gs-mb-2'],
            'required' => $campo[1]));
          break;
        case 'float':
          $builder->add($nome, NumberType::class, array('label' => false,
            'attr' => ['class' => 'gs-mb-2'],
            'required' => $campo[1]));
          break;
        case 'bool':
          $builder->add($nome, ChoiceType::class, array('label' => false,
            'attr' => ['class' => 'gs-mb-2'],
            'choices' => array('label.si' => true, 'label.no' => false),
            'required' => $campo[1]));
          break;
        case 'date':
          $builder->add($nome, DateType::class, array('label' => false,
            'attr' => ['class' => 'gs-mb-2'],
            'widget' => 'single_text',
            'required' => $campo[1]));
          break;
        case 'time':
          $builder->add($nome, TimeType::class, array('label' => false,
            'attr' => ['class' => 'gs-mb-2'],
            'widget' => 'single_text',
            'required' => $campo[1]));
          break;
      }
    }
  }

  /**
   * Configura le opzioni usate nel form
   *
   * @param OptionsResolver $resolver Gestore delle opzioni
   */
  public function configureOptions(OptionsResolver $resolver) {
    $resolver->setDefined('returnUrl');
    $resolver->setDefined('dati');
    $resolver->setDefaults(array(
      'allow_extra_fields' => true,
      'returnUrl' => null,
      'dati' => null,
      'data_class' => null));
  }

}
