<?php

namespace Drupal\mahlzeit_vocabulary\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\mahlzeit_vocabulary\Service\VocabularyService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the vocabulary administration listing.
 */
final class VocabularyAdminController implements ContainerInjectionInterface {

  /**
   * Constructs a VocabularyAdminController.
   */
  public function __construct(
    private readonly VocabularyService $vocabularyService,
  ) {
  }

  /**
   * Lists all vocabulary entries with management actions.
   */
  public function listing(): array {
    $items = $this->vocabularyService->getAllVocabulary();
    foreach ($items as &$item) {
      $item['edit_url'] = Url::fromRoute('mahlzeit_vocabulary.edit', ['vocabulary_id' => $item['id']])->toString();
      $item['delete_url'] = Url::fromRoute('mahlzeit_vocabulary.delete', ['vocabulary_id' => $item['id']])->toString();
    }
    unset($item);

    return [
      '#theme' => 'mahlzeit_vocabulary_admin',
      '#items' => $items,
      '#add_url' => Url::fromRoute('mahlzeit_vocabulary.add')->toString(),
      '#sync_url' => Url::fromRoute('mahlzeit_vocabulary.sync')->toString(),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('mahlzeit_vocabulary.vocabulary'));
  }

}
