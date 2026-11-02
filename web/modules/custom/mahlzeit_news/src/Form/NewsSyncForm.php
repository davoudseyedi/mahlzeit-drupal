<?php

namespace Drupal\mahlzeit_news\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mahlzeit_news\Service\NewsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a manual news synchronization form.
 */
final class NewsSyncForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly NewsService $newsService,
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mahlzeit_news_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $last_sync = (int) $this->state->get('mahlzeit_news.last_sync', 0);
    $last_sync_label = $last_sync
      ? $this->dateFormatter->format($last_sync, 'custom', 'M d, Y · H:i')
      : $this->t('Not synchronized yet');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('News is imported from the public Tagesschau RSS feeds and cached locally for the Mahlzeit news page.') . '</p>',
    ];
    $form['last_sync'] = [
      '#markup' => '<p><strong>' . $this->t('Last synchronization:') . '</strong> ' . $last_sync_label . '</p>',
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['sync'] = [
      '#type' => 'submit',
      '#value' => $this->t('Synchronize news now'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $count = $this->newsService->sync(TRUE, TRUE);
    $this->messenger()->addStatus($this->t('News synchronization finished. @count items were imported or updated.', ['@count' => $count]));
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mahlzeit_news.news'),
      $container->get('state'),
      $container->get('date.formatter'),
    );
  }

}
