<?php

namespace Drupal\mahlzeit_vocabulary\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\mahlzeit_vocabulary\Service\VocabularyService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the public Vokabeln page.
 */
final class VocabularyController implements ContainerInjectionInterface {

  /**
   * Constructs a VocabularyController.
   */
  public function __construct(
    private readonly VocabularyService $vocabularyService,
  ) {
  }

  /**
   * Renders the daily vocabulary and the interactive library.
   */
  public function listing(): array {
    $items = $this->vocabularyService->getActiveVocabulary();

    return [
      '#theme' => 'mahlzeit_vocabulary_listing',
      '#today' => $items[0] ?? NULL,
      '#items' => $items,
      '#count' => count($items),
      '#attached' => [
        'library' => ['mahlzeit_vocabulary/flashcards'],
      ],
      '#cache' => [
        // Admin edits and cron imports should be visible immediately.
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('mahlzeit_vocabulary.vocabulary'));
  }

}
