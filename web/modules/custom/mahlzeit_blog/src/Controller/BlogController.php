<?php

namespace Drupal\mahlzeit_blog\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the public Mahlzeit blog listing.
 */
final class BlogController implements ContainerInjectionInterface {

  /**
   * Constructs a BlogController.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * Renders published blog posts in reverse chronological order.
   */
  public function listing(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->pager(9);
    $nids = $query->execute();
    $nodes = $storage->loadMultiple($nids);
    $posts = [];

    foreach ($nids as $nid) {
      if (!isset($nodes[$nid])) {
        continue;
      }

      $node = $nodes[$nid];
      $created = $node->getCreatedTime();
      $categories = $this->getTermLabels($node, 'field_blog_category');
      $posts[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'date' => $this->dateFormatter->format($created, 'custom', 'M d, Y'),
        'date_iso' => gmdate(DATE_ATOM, $created),
        'excerpt' => $this->getExcerpt($node),
        'category' => $categories[0] ?? '',
        'tags' => $this->getTermLabels($node, 'field_blog_tags'),
        'tone' => ['green', 'yellow', 'red'][$node->id() % 3],
        'image' => $this->getHeroImage($node),
      ];
    }

    return [
      '#theme' => 'mahlzeit_blog_listing',
      '#posts' => $posts,
      '#pager' => ['#type' => 'pager'],
      '#can_create' => $this->currentUser->hasPermission('create blog content'),
      '#create_url' => Url::fromRoute('node.add', ['node_type' => 'blog'])->toString(),
      '#cache' => [
        'contexts' => [
          'url.query_args',
          'user.node_grants:view',
          'user.permissions',
        ],
        'tags' => ['node_list'],
      ],
    ];
  }

  /**
   * Builds a safe, short card summary from the authored summary or body.
   */
  private function getExcerpt(NodeInterface $node): string {
    $summary = '';
    if ($node->hasField('field_blog_body') && !$node->get('field_blog_body')->isEmpty()) {
      $summary = (string) ($node->get('field_blog_body')->summary ?: $node->get('field_blog_body')->value);
    }

    $summary = Html::decodeEntities(strip_tags($summary));
    return Unicode::truncate(trim($summary), 180, TRUE, TRUE);
  }

  /**
   * Builds the optional hero image render array for a blog card.
   */
  private function getHeroImage(NodeInterface $node): ?array {
    if (!$node->hasField('field_blog_hero') || $node->get('field_blog_hero')->isEmpty()) {
      return NULL;
    }

    return $node->get('field_blog_hero')->view([
      'label' => 'hidden',
      'type' => 'image',
      'settings' => [
        'image_style' => 'wide',
        'image_link' => '',
        'image_loading' => ['attribute' => 'lazy'],
      ],
    ]);
  }

  /**
   * Returns labels from a taxonomy term reference field.
   */
  private function getTermLabels(NodeInterface $node, string $fieldName): array {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return [];
    }

    $labels = [];
    foreach ($node->get($fieldName)->referencedEntities() as $term) {
      $labels[] = (string) $term->label();
    }
    return $labels;
  }

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('current_user'),
    );
  }

}
