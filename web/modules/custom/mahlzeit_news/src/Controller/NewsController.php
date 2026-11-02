<?php

namespace Drupal\mahlzeit_news\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\mahlzeit_news\Service\NewsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides the public Mahlzeit news pages.
 */
final class NewsController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a NewsController.
   */
  public function __construct(
    private readonly NewsService $newsService,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * Renders the cached news feed with category and date filters.
   */
  public function listing(Request $request): array {
    $category = (string) $request->query->get('category', '');
    $date_filter = (string) $request->query->get('range', 'all');

    if (!$this->newsService->isValidCategory($category)) {
      $category = '';
    }
    if (!$this->newsService->isValidDateFilter($date_filter)) {
      $date_filter = 'all';
    }

    $items = $this->decorateItems($this->newsService->getNews($category ?: NULL, $date_filter));
    $categories = $this->buildCategoryLinks($category, $date_filter);
    $date_filters = $this->buildDateFilterLinks($category, $date_filter);

    return [
      '#theme' => 'mahlzeit_news_listing',
      '#items' => $items,
      '#categories' => $categories,
      '#date_filters' => $date_filters,
      '#selected_category' => $category,
      '#selected_date_filter' => $date_filter,
      '#pager' => ['#type' => 'pager'],
      '#cache' => [
        'contexts' => ['url.query_args'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Renders one news item.
   */
  public function detail(string $news_slug): array {
    $item = $this->newsService->getNewsItemByMachineName($news_slug);
    if (!$item) {
      throw new NotFoundHttpException();
    }

    $item = $this->decorateItem($item);
    $item['back_url'] = Url::fromRoute('mahlzeit_news.list')->toString();

    return [
      '#theme' => 'mahlzeit_news_detail',
      '#item' => $item,
      '#cache' => [
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Redirects old numeric news URLs to their pretty machine-name URL.
   */
  public function detailLegacy(int $news_id): RedirectResponse {
    $item = $this->newsService->getNewsItem($news_id);
    if (!$item) {
      throw new NotFoundHttpException();
    }

    return new RedirectResponse(Url::fromRoute('mahlzeit_news.detail', [
      'news_slug' => $item['machine_name'],
    ])->toString(), 301);
  }

  /**
   * Adds presentation values used by the Twig templates.
   */
  private function decorateItems(array $items): array {
    return array_map(fn(array $item): array => $this->decorateItem($item), $items);
  }

  /**
   * Adds presentation values to a single normalized service item.
   */
  private function decorateItem(array $item): array {
    $published = (int) ($item['published'] ?: $item['created']);
    $item['date'] = $this->dateFormatter->format($published, 'custom', 'M d, Y · H:i');
    $item['date_iso'] = gmdate(DATE_ATOM, $published);
    $item['category_label'] = $this->newsService->getCategoryLabel($item['category']);
    $item['detail_url'] = Url::fromRoute('mahlzeit_news.detail', ['news_slug' => $item['machine_name']])->toString();
    return $item;
  }

  /**
   * Builds category filter links while retaining the date filter.
   */
  private function buildCategoryLinks(string $selected, string $date_filter): array {
    $links = [
      ['key' => '', 'label' => (string) $this->t('All news')],
    ];

    foreach ($this->newsService->getCategories() as $key => $label) {
      $links[] = ['key' => $key, 'label' => $label];
    }

    foreach ($links as &$link) {
      $query = [];
      if ($link['key'] !== '') {
        $query['category'] = $link['key'];
      }
      if ($date_filter !== 'all') {
        $query['range'] = $date_filter;
      }
      $link['url'] = Url::fromRoute('mahlzeit_news.list', [], ['query' => $query])->toString();
      $link['active'] = $link['key'] === $selected;
    }
    unset($link);

    return $links;
  }

  /**
   * Builds date filter links while retaining the category filter.
   */
  private function buildDateFilterLinks(string $selected_category, string $selected): array {
    $filters = [
      'all' => (string) $this->t('All dates'),
      'today' => (string) $this->t('Today'),
      '7d' => (string) $this->t('Last 7 days'),
      '30d' => (string) $this->t('Last 30 days'),
    ];
    $links = [];

    foreach ($filters as $key => $label) {
      $query = [];
      if ($selected_category !== '') {
        $query['category'] = $selected_category;
      }
      if ($key !== 'all') {
        $query['range'] = $key;
      }
      $links[] = [
        'key' => $key,
        'label' => $label,
        'url' => Url::fromRoute('mahlzeit_news.list', [], ['query' => $query])->toString(),
        'active' => $key === $selected,
      ];
    }

    return $links;
  }

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mahlzeit_news.news'),
      $container->get('date.formatter'),
    );
  }

}
