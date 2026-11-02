<?php

namespace Drupal\mahlzeit_consultation\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mahlzeit_consultation\Service\ConsultationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public form for requesting a German learning consultation.
 */
final class ConsultationForm extends FormBase {

  /**
   * Constructs a ConsultationForm.
   */
  public function __construct(
    private readonly ConsultationService $consultationService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mahlzeit_consultation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($form_state->get('submitted')) {
      return [
        'confirmation' => [
          '#markup' => '<div class="consultation-success" role="status"><span class="consultation-success__icon" aria-hidden="true">✓</span><div><h2>' . $this->t('Request received.') . '</h2><p>' . $this->t('Thanks — our team will review your request and get back to you using your preferred contact method.') . '</p><a class="button button--secondary" href="' . $this->getUrl('<front>') . '">' . $this->t('Back to home') . '</a></div></div>',
        ],
      ];
    }

    $form['intro'] = [
      '#markup' => '<p class="consultation-form__intro">' . $this->t('Tell us a little about your German goals and we will help you find the right next step.') . '</p>',
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full name'),
      '#maxlength' => 160,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'name'],
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'email'],
    ];
    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#description' => $this->t('Optional — only needed if you prefer a call.'),
      '#attributes' => ['autocomplete' => 'tel'],
    ];
    $form['level'] = [
      '#type' => 'select',
      '#title' => $this->t('Current German level'),
      '#options' => [
        '' => $this->t('Choose your level'),
        'A1' => 'A1',
        'A2' => 'A2',
        'B1' => 'B1',
        'B2' => 'B2',
        'C1' => 'C1+',
        'unsure' => $this->t('Not sure yet'),
      ],
    ];
    $form['preferred_contact'] = [
      '#type' => 'radios',
      '#title' => $this->t('How should we reach you?'),
      '#description' => $this->t('Choose the option that feels easiest.'),
      '#options' => [
        'email' => $this->t('Email'),
        'phone' => $this->t('Phone call'),
        'either' => $this->t('Either is fine'),
      ],
      '#default_value' => 'email',
      '#required' => TRUE,
    ];
    $form['preferred_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preferred time'),
      '#description' => $this->t('For example: weekday evenings or Saturday morning.'),
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What would you like help with?'),
      '#rows' => 5,
      '#required' => TRUE,
      '#attributes' => ['placeholder' => $this->t('Tell us about your goals, schedule or questions.')],
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Request a consultation'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->consultationService->saveRequest($form_state->getValues());
    $form_state->set('submitted', TRUE);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Returns a URL string without introducing a controller dependency.
   */
  private function getUrl(string $routeName): string {
    return \Drupal\Core\Url::fromRoute($routeName)->toString();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('mahlzeit_consultation.consultation'));
  }

}
