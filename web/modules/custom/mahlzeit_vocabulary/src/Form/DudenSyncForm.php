<?php

namespace Drupal\mahlzeit_vocabulary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\mahlzeit_vocabulary\Service\VocabularyService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a manual Duden synchronization action.
 */
final class DudenSyncForm extends FormBase {

  /**
   * Constructs a DudenSyncForm.
   */
  public function __construct(
    private readonly VocabularyService $vocabularyService,
    private readonly StateInterface $state,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mahlzeit_vocabulary_duden_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $lastSync = (int) $this->state->get('mahlzeit_vocabulary.last_duden_sync', 0);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('This imports the current Duden “Wort des Tages” and keeps it in the public vocabulary library.') . '</p>',
    ];
    $form['last_sync'] = [
      '#markup' => '<p><strong>' . $this->t('Last synchronization:') . '</strong> ' . ($lastSync ? date('Y-m-d H:i', $lastSync) : $this->t('Not synchronized yet')) . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['sync'] = [
      '#type' => 'submit',
      '#value' => $this->t('Synchronize Duden now'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $item = $this->vocabularyService->syncDuden(TRUE);
      $this->messenger()->addStatus($this->t('Duden synchronization finished. “@title” is available.', [
        '@title' => $item['title'] ?? $this->t('The word'),
      ]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Duden synchronization failed: @message', ['@message' => $exception->getMessage()]));
    }
    $form_state->setRedirect('mahlzeit_vocabulary.admin');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mahlzeit_vocabulary.vocabulary'),
      $container->get('state'),
    );
  }

}
