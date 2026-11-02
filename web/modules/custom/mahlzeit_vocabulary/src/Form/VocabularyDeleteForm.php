<?php

namespace Drupal\mahlzeit_vocabulary\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Drupal\mahlzeit_vocabulary\Service\VocabularyService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a vocabulary item.
 */
final class VocabularyDeleteForm extends ConfirmFormBase {

  /**
   * Constructs a VocabularyDeleteForm.
   */
  public function __construct(
    private readonly VocabularyService $vocabularyService,
    private readonly CurrentRouteMatch $currentRouteMatch,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mahlzeit_vocabulary_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $item = $this->getItem();
    return (string) $this->t('Delete “@title”?', ['@title' => $item['title'] ?? 'this vocabulary item']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('mahlzeit_vocabulary.admin');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = (int) $this->currentRouteMatch->getParameter('vocabulary_id');
    $this->vocabularyService->delete($id);
    $this->messenger()->addStatus($this->t('Vocabulary item deleted.'));
    $form_state->setRedirect('mahlzeit_vocabulary.admin');
  }

  /**
   * Returns the current item when it still exists.
   *
   * @return array<string, mixed>
   */
  private function getItem(): array {
    $id = (int) $this->currentRouteMatch->getParameter('vocabulary_id');
    return $this->vocabularyService->getVocabulary($id) ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mahlzeit_vocabulary.vocabulary'),
      $container->get('current_route_match'),
    );
  }

}
