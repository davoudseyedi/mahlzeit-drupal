<?php

namespace Drupal\mahlzeit_vocabulary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Drupal\mahlzeit_vocabulary\Service\VocabularyService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides add/edit forms for public vocabulary entries.
 */
final class VocabularyForm extends FormBase {

  /**
   * Constructs a VocabularyForm.
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
    return 'mahlzeit_vocabulary_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $id = (int) $this->currentRouteMatch->getParameter('vocabulary_id');
    $item = $id ? $this->vocabularyService->getVocabulary($id) : NULL;
    if ($id && !$item) {
      throw new NotFoundHttpException();
    }

    $form['level'] = [
      '#type' => 'select',
      '#title' => $this->t('Level'),
      '#options' => [
        'A1' => 'A1',
        'A2' => 'A2',
        'B1' => 'B1',
        'B2' => 'B2',
        'C1' => 'C1',
        'ALL' => $this->t('All levels'),
      ],
      '#default_value' => $item['level'] ?? 'A1',
      '#required' => TRUE,
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('German word'),
      '#default_value' => $item['title'] ?? '',
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['part_of_speech'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Part of speech'),
      '#description' => $this->t('For example: Substantiv, Verb or Adjektiv.'),
      '#default_value' => $item['part_of_speech'] ?? '',
      '#maxlength' => 80,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Meaning'),
      '#default_value' => $item['description'] ?? '',
      '#rows' => 5,
      '#required' => TRUE,
    ];
    $form['pronunciation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pronunciation'),
      '#description' => $this->t('Optional syllable or pronunciation hint.'),
      '#default_value' => $item['pronunciation'] ?? '',
    ];
    $form['published_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Published date'),
      '#default_value' => $item['published_date'] ?? date('Y-m-d'),
    ];
    $form['sort_order'] = [
      '#type' => 'number',
      '#title' => $this->t('Sort order'),
      '#description' => $this->t('Lower numbers appear first. Entries with the same number use newest first.'),
      '#default_value' => $item['sort_order'] ?? 0,
      '#min' => 0,
    ];
    $form['is_active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show this word publicly'),
      '#default_value' => $item['is_active'] ?? TRUE,
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $id ? $this->t('Save changes') : $this->t('Add vocabulary item'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('mahlzeit_vocabulary.admin'),
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = (int) $this->currentRouteMatch->getParameter('vocabulary_id');
    $this->vocabularyService->save($form_state->getValues(), $id ?: NULL);
    $this->messenger()->addStatus($this->t('Vocabulary item saved.'));
    $form_state->setRedirect('mahlzeit_vocabulary.admin');
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
