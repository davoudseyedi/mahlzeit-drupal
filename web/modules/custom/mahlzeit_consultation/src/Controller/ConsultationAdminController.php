<?php

namespace Drupal\mahlzeit_consultation\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\mahlzeit_consultation\Service\ConsultationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the consultation request inbox.
 */
final class ConsultationAdminController implements ContainerInjectionInterface {

  /**
   * Constructs a ConsultationAdminController.
   */
  public function __construct(
    private readonly ConsultationService $consultationService,
  ) {
  }

  /**
   * Lists the newest submitted requests.
   */
  public function listing(): array {
    return [
      '#theme' => 'mahlzeit_consultation_admin',
      '#requests' => $this->consultationService->getRequests(),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('mahlzeit_consultation.consultation'));
  }

}
