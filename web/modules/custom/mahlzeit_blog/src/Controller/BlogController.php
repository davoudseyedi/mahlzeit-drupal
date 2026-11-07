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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
  public function listing(Request $request, ?string $category = NULL): array|RedirectResponse {
    $storage = $this->entityTypeManager->getStorage('node');
    $categories = $this->getCategories();
    $categories_by_slug = [];
    foreach ($categories as $category_item) {
      $categories_by_slug[$category_item['slug']] = $category_item;
    }

    if ($category !== NULL) {
      if (!isset($categories_by_slug[$category])) {
        $legacy_post_url = $this->getLegacyPostUrl($category);
        if ($legacy_post_url !== NULL) {
          return new RedirectResponse($legacy_post_url, 301);
        }
        throw new NotFoundHttpException();
      }
      $selected_category = $categories_by_slug[$category]['id'];
    }
    else {
      $category_ids = array_column($categories, 'id');
      $selected_category = (int) $request->query->get('category', 0);
      if (!in_array($selected_category, $category_ids, TRUE)) {
        $selected_category = 0;
      }

      // Keep old filter URLs useful while moving them to the readable path.
      if ($selected_category > 0) {
        foreach ($categories as $category_item) {
          if ($category_item['id'] === $selected_category) {
            return new RedirectResponse($category_item['url'], 301);
          }
        }
      }
    }

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->pager(9);
    if ($selected_category > 0) {
      $query->condition('field_blog_category.target_id', $selected_category);
    }
    $nids = $query->execute();
    $nodes = $storage->loadMultiple($nids);
    $posts = [];

    foreach ($nids as $nid) {
      if (!isset($nodes[$nid])) {
        continue;
      }

      $node = $nodes[$nid];
      $created = $node->getCreatedTime();
      $post_categories = $this->getTermLabels($node, 'field_blog_category');
      $posts[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'date' => $this->dateFormatter->format($created, 'custom', 'M d, Y'),
        'date_iso' => gmdate(DATE_ATOM, $created),
        'excerpt' => $this->getExcerpt($node),
        'category' => $post_categories[0] ?? '',
        'tags' => $this->getTermLabels($node, 'field_blog_tags'),
        'tone' => ['green', 'yellow', 'red'][$node->id() % 3],
        'image' => $this->getHeroImage($node),
      ];
    }

    return [
      '#theme' => 'mahlzeit_blog_listing',
      '#posts' => $posts,
      '#categories' => $categories,
      '#all_categories_url' => Url::fromRoute('mahlzeit_blog.list')->toString(),
      '#selected_category' => $selected_category,
      '#pager' => ['#type' => 'pager'],
      '#can_create' => $this->currentUser->hasPermission('create blog content'),
      '#create_url' => Url::fromRoute('node.add', ['node_type' => 'blog'])->toString(),
      '#cache' => [
        'contexts' => [
          'url.query_args',
          'user.node_grants:view',
          'user.permissions',
        ],
        'tags' => ['node_list', 'taxonomy_term_list:blog_category'],
      ],
    ];
  }

  /**
   * Returns category filter options for the public blog listing.
   *
   * @return array<int, array{id: int, slug: string, label: string, url: string}>
   */
  private function getCategories(): array {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree('blog_category');
    $categories = [];

    foreach ($terms as $term) {
      $id = (int) $term->tid;
      if ($id === 0) {
        continue;
      }
      $categories[] = [
        'id' => $id,
        'slug' => mahlzeit_blog_machine_name((string) $term->name, $id),
        'label' => (string) $term->name,
        'url' => Url::fromRoute('mahlzeit_blog.category', [
          'category' => mahlzeit_blog_machine_name((string) $term->name, $id),
        ])->toString(),
      ];
    }

    usort($categories, static fn(array $first, array $second): int => strcasecmp($first['label'], $second['label']));
    return $categories;
  }

  /**
   * Finds the new URL for an article using its former /blog/{slug} path.
   */
  private function getLegacyPostUrl(string $slug): ?string {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog')
      ->condition('status', NodeInterface::PUBLISHED)
      ->execute();

    foreach ($storage->loadMultiple($nids) as $node) {
      if (mahlzeit_blog_machine_name($node->label(), (int) $node->id()) === $slug) {
        return $node->toUrl()->toString();
      }
    }

    return NULL;
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
