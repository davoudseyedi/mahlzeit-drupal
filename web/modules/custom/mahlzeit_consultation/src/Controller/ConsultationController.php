<?php

namespace Drupal\mahlzeit_consultation\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\mahlzeit_consultation\Form\ConsultationForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the public consultation request page.
 */
final class ConsultationController implements ContainerInjectionInterface {

  /**
   * Constructs a ConsultationController.
   */
  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
  ) {
  }

  /**
   * Renders the consultation request form.
   */
  public function form(): array {
    return [
      '#theme' => 'mahlzeit_consultation_page',
      '#form' => $this->formBuilder->getForm(ConsultationForm::class),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('form_builder'));
  }

}
