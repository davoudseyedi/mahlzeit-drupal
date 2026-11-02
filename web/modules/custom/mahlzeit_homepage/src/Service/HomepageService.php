<?php

namespace Drupal\mahlzeit_homepage\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\mahlzeit_news\Service\NewsService;
use Drupal\mahlzeit_vocabulary\Service\VocabularyService;
use Drupal\Node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Collects live app data and the latest local learning content for the home.
 */
final class HomepageService {

  private const REMOTE_CACHE_ID = 'mahlzeit_homepage.remote_content';
  private const REMOTE_CACHE_TTL = 300;

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly NewsService $newsService,
    private readonly VocabularyService $vocabularyService,
  ) {
    $this->logger = $loggerFactory->get('mahlzeit_homepage');
  }

  /**
   * Returns all homepage sections in their template-ready shape.
   *
   * @return array{exams: array<int, array<string, mixed>>, plans: array<int, array<string, mixed>>, vocabulary: array<string, mixed>|null, news: array<int, array<string, mixed>>, blog: array<int, array<string, mixed>>}
   */
  public function getData(): array {
    $remote = $this->getRemoteContent();

    return [
      'exams' => $remote['exams'],
      'plans' => $remote['plans'],
      'vocabulary' => $this->getVocabulary(),
      'news' => $this->getNews(),
      'blog' => $this->getBlog(),
    ];
  }

  /**
   * Gets API data from cache first, keeping the page responsive during outages.
   *
   * @return array{exams: array<int, array<string, mixed>>, plans: array<int, array<string, mixed>>}
   */
  private function getRemoteContent(): array {
    $cached = $this->cache->get(self::REMOTE_CACHE_ID);
    $cachedData = is_array($cached?->data) ? $cached->data : [];
    $hasFreshCache = $cached && ($cached->expire === 0 || $cached->expire > time());

    if ($hasFreshCache) {
      return [
        'exams' => is_array($cachedData['exams'] ?? NULL) ? $cachedData['exams'] : $this->fallbackExamLevels(),
        'plans' => is_array($cachedData['plans'] ?? NULL) ? $cachedData['plans'] : [],
      ];
    }

    $exams = $this->fetchExamLevels();
    $plans = $this->fetchPlans();

    // Keep the last successful API response when the upstream is temporarily
    // unavailable. The exam fallback is only a first-render safety net until
    // the public API endpoint is deployed; normal requests are API-backed.
    if ($exams === NULL) {
      $exams = is_array($cachedData['exams'] ?? NULL) ? $cachedData['exams'] : $this->fallbackExamLevels();
    }
    if ($plans === NULL) {
      $plans = is_array($cachedData['plans'] ?? NULL) ? $cachedData['plans'] : [];
    }

    $data = ['exams' => $exams, 'plans' => $plans];
    $this->cache->set(self::REMOTE_CACHE_ID, $data, time() + self::REMOTE_CACHE_TTL);
    return $data;
  }

  /**
   * Reads the public ÖSD catalog endpoint.
   *
   * @return array<int, array<string, mixed>>|null
   */
  private function fetchExamLevels(): ?array {
    $payload = $this->fetchJson('/public/speaking/exams');
    // Supports an already-public deployment that exposes the existing route.
    if ($payload === NULL) {
      $payload = $this->fetchJson('/chat/speaking/exams');
    }
    if (!is_array($payload) || !is_array($payload['levels'] ?? NULL)) {
      return NULL;
    }

    $levels = [];
    foreach ($payload['levels'] as $levelData) {
      if (!is_array($levelData)) {
        continue;
      }

      $level = strtoupper(trim((string) ($levelData['level'] ?? '')));
      if ($level === '' || !is_array($levelData['modules'] ?? NULL)) {
        continue;
      }

      $modules = [];
      foreach ($levelData['modules'] as $module) {
        if (!is_array($module) || empty($module['slug'])) {
          continue;
        }

        $parts = is_array($module['teils'] ?? NULL) ? $module['teils'] : [];
        $minutes = 0;
        foreach ($parts as $part) {
          if (is_array($part)) {
            $minutes += (int) ($part['durationMinutes'] ?? 0);
          }
        }

        $modules[] = [
          'slug' => (string) $module['slug'],
          'title' => $this->examTitle($level, (string) $module['slug']),
          'description' => '',
          'topic' => $this->examTopic((string) $module['slug']),
          'minutes' => $minutes,
          'teil_count' => (int) ($module['teilCount'] ?? count($parts)),
        ];
      }

      if ($modules) {
        $levels[] = [
          'level' => $level,
          'label' => $this->levelLabel($level),
          'modules' => $modules,
        ];
      }
    }

    return $levels;
  }

  /**
   * Reads active subscription plans from the public payment API.
   *
   * @return array<int, array<string, mixed>>|null
   */
  private function fetchPlans(): ?array {
    $payload = $this->fetchJson('/payment/plans');
    if (!is_array($payload)) {
      return NULL;
    }

    $plans = [];
    foreach ($payload as $plan) {
      if (!is_array($plan) || empty($plan['id'])) {
        continue;
      }

      $code = strtolower(trim((string) ($plan['code'] ?? '')));
      $plans[] = [
        'id' => (int) $plan['id'],
        'code' => $code,
        'tier' => $this->planTier($code, (string) ($plan['name'] ?? '')),
        'name' => $this->planName($code),
        'description' => $this->planDescription($code, (string) ($plan['description'] ?? '')),
        'price_usd' => (float) ($plan['priceUsd'] ?? 0),
        'price_rial' => (int) ($plan['priceRial'] ?? 0),
        'duration_months' => (int) ($plan['durationMonths'] ?? 1),
        'monthly_minutes_limit' => (int) ($plan['monthlyMinutesLimit'] ?? 0),
        'text_chat_limit' => (int) ($plan['textChatLimit'] ?? 0),
        'mistake_notebook_limit' => (int) ($plan['mistakeNotebookLimit'] ?? 0),
        'is_recommended' => !empty($plan['isRecommended']),
      ];
    }

    return $plans;
  }

  /**
   * Returns the current local vocabulary item, with an API fallback.
   *
   * @return array<string, mixed>|null
   */
  private function getVocabulary(): ?array {
    $local = $this->vocabularyService->getTodayVocabulary();
    if (is_array($local)) {
      return $this->normalizeVocabulary($local);
    }

    $remote = $this->fetchJson('/vocabularies/today');
    if (!is_array($remote) || empty($remote['title'])) {
      return NULL;
    }

    return $this->normalizeVocabulary([
      'level' => (string) ($remote['level'] ?? 'ALL'),
      'title' => (string) $remote['title'],
      'part_of_speech' => (string) ($remote['partOfSpeech'] ?? ''),
      'description' => html_entity_decode((string) ($remote['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
      'pronunciation' => (string) ($remote['pronunciation'] ?? ''),
      'published_date' => (string) ($remote['publishedDate'] ?? ''),
    ]);
  }

  /**
   * Returns the latest locally synchronized news items.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getNews(): array {
    try {
    // The news service uses a pager internally, so slice the result as a
    // final guard to keep the homepage section intentionally compact.
    $items = array_slice($this->newsService->getNews(NULL, 'all', 12), 0, 3);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Homepage news could not be loaded: @message', ['@message' => $exception->getMessage()]);
      return [];
    }

    $news = [];
    foreach ($items as $item) {
      $published = (int) ($item['published'] ?? $item['created'] ?? time());
      $news[] = [
        'id' => (int) ($item['id'] ?? 0),
        'title' => (string) ($item['title'] ?? ''),
        'excerpt' => $this->excerpt((string) ($item['description'] ?? ''), 170),
        'category' => $this->newsService->getCategoryLabel((string) ($item['category'] ?? '')),
        'date' => $this->dateFormatter->format($published, 'custom', 'M d, Y'),
        'date_iso' => gmdate(DATE_ATOM, $published),
        'detail_url' => Url::fromRoute('mahlzeit_news.detail', ['news_slug' => (string) ($item['machine_name'] ?? '')])->toString(),
        'source_url' => (string) ($item['url'] ?? ''),
        'tone' => (string) ($item['tone'] ?? 'green'),
      ];
    }
    return $news;
  }

  /**
   * Returns the latest published blog nodes.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getBlog(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->range(0, 3)
      ->execute();
    $nodes = $storage->loadMultiple($nids);
    $posts = [];

    foreach ($nids as $nid) {
      if (!isset($nodes[$nid])) {
        continue;
      }

      $node = $nodes[$nid];
      $created = $node->getCreatedTime();
      $posts[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'date' => $this->dateFormatter->format($created, 'custom', 'M d, Y'),
        'date_iso' => gmdate(DATE_ATOM, $created),
        'excerpt' => $this->getBlogExcerpt($node),
        'category' => $this->getTermLabels($node, 'field_blog_category')[0] ?? '',
        'image' => $this->getHeroImage($node),
      ];
    }

    return $posts;
  }

  /**
   * Fetches one JSON endpoint from the app API.
   */
  private function fetchJson(string $path): mixed {
    $base = rtrim((string) Settings::get('mahlzeit_app_api_base', 'https://app.mahlzeitlab.com/api'), '/');
    try {
      $response = $this->httpClient->request('GET', $base . $path, [
        'http_errors' => FALSE,
        'timeout' => 6,
        'connect_timeout' => 3,
        'headers' => [
          'Accept' => 'application/json',
          'User-Agent' => 'Mahlzeit homepage/1.0 (+https://mahlzeitlab.com)',
        ],
      ]);
      if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        $this->logger->notice('Homepage API endpoint @path returned HTTP @status.', [
          '@path' => $path,
          '@status' => $response->getStatusCode(),
        ]);
        return NULL;
      }
      return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Homepage API endpoint @path failed: @message', [
        '@path' => $path,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function fallbackExamLevels(): array {
    $modules = [
      ['level' => 'A1', 'slug' => 'a1-modelltest-1', 'title' => 'A1 · Model test 1: Everyday life', 'topic' => 'Everyday life and family', 'minutes' => 7, 'teil_count' => 3],
      ['level' => 'A1', 'slug' => 'a1-modelltest-2', 'title' => 'A1 · Model test 2: Home and leisure', 'topic' => 'Home and leisure', 'minutes' => 7, 'teil_count' => 3],
      ['level' => 'A2', 'slug' => 'a2-modelltest-1', 'title' => 'A2 · Model test 1: Celebrations', 'topic' => 'Celebrations and invitations', 'minutes' => 10, 'teil_count' => 3],
      ['level' => 'A2', 'slug' => 'a2-modelltest-2', 'title' => 'A2 · Model test 2: Travel', 'topic' => 'Travel and holidays', 'minutes' => 10, 'teil_count' => 3],
      ['level' => 'B1', 'slug' => 'b1-modelltest-1', 'title' => 'B1 · Model test 1: Health', 'topic' => 'Health and sport', 'minutes' => 11, 'teil_count' => 3],
      ['level' => 'B1', 'slug' => 'b1-modelltest-2', 'title' => 'B1 · Model test 2: Media', 'topic' => 'Media and mobile phones', 'minutes' => 11, 'teil_count' => 3],
      ['level' => 'B2', 'slug' => 'b2-modelltest-1', 'title' => 'B2 · Model test 1: Work', 'topic' => 'Work and the future', 'minutes' => 13, 'teil_count' => 3],
      ['level' => 'B2', 'slug' => 'b2-modelltest-2', 'title' => 'B2 · Model test 2: Environment', 'topic' => 'Environment and cities', 'minutes' => 13, 'teil_count' => 3],
    ];
    $levels = [];
    foreach ($modules as $module) {
      $level = $module['level'];
      if (!isset($levels[$level])) {
        $levels[$level] = [
          'level' => $level,
          'label' => $this->levelLabel($level),
          'modules' => [],
        ];
      }
      $levels[$level]['modules'][] = $module;
    }
    return array_values($levels);
  }

  private function levelLabel(string $level): string {
    return match ($level) {
      'A1' => 'Beginner',
      'A2' => 'Elementary',
      'B1' => 'Intermediate',
      'B2' => 'Upper intermediate',
      default => 'Level ' . $level,
    };
  }

  private function planTier(string $code, string $name): string {
    return match ($code) {
      'pro' => 'PRO',
      'standard' => 'STANDARD',
      default => 'ECONOMIC',
    };
  }

  private function planName(string $code): string {
    return match ($code) {
      'economic' => 'Economic',
      'standard' => 'Standard',
      'pro' => 'Pro',
      default => 'Mahlzeit plan',
    };
  }

  private function planDescription(string $code, string $description): string {
    return match ($code) {
      'economic' => '100 minutes per month',
      'standard' => '300 minutes per month',
      'pro' => '700 minutes per month',
      default => preg_match('/[\x{0600}-\x{06FF}]/u', $description) ? 'Flexible learning access' : $description,
    };
  }

  private function examTitle(string $level, string $slug): string {
    return match ($slug) {
      'a1-modelltest-1' => 'A1 · Model test 1: Everyday life',
      'a1-modelltest-2' => 'A1 · Model test 2: Home and leisure',
      'a2-modelltest-1' => 'A2 · Model test 1: Celebrations',
      'a2-modelltest-2' => 'A2 · Model test 2: Travel',
      'b1-modelltest-1' => 'B1 · Model test 1: Health',
      'b1-modelltest-2' => 'B1 · Model test 2: Media',
      'b2-modelltest-1' => 'B2 · Model test 1: Work',
      'b2-modelltest-2' => 'B2 · Model test 2: Environment',
      default => $level . ' · Model test',
    };
  }

  private function examTopic(string $slug): string {
    return match ($slug) {
      'a1-modelltest-1' => 'Everyday life and family',
      'a1-modelltest-2' => 'Home and leisure',
      'a2-modelltest-1' => 'Celebrations and invitations',
      'a2-modelltest-2' => 'Travel and holidays',
      'b1-modelltest-1' => 'Health and sport',
      'b1-modelltest-2' => 'Media and mobile phones',
      'b2-modelltest-1' => 'Work and the future',
      'b2-modelltest-2' => 'Environment and cities',
      default => '',
    };
  }

  /**
   * Keeps the vocabulary word German, while presenting its metadata in English.
   *
   * @param array<string, mixed> $vocabulary
   *   Normalized vocabulary item.
   *
   * @return array<string, mixed>
   *   Vocabulary item with English part-of-speech metadata.
   */
  private function normalizeVocabulary(array $vocabulary): array {
    $part = strtolower(trim((string) ($vocabulary['part_of_speech'] ?? '')));
    $vocabulary['part_of_speech'] = match ($part) {
      'pronomen' => 'Pronoun',
      'nomen', 'substantiv' => 'Noun',
      'adjektiv' => 'Adjective',
      'adverb' => 'Adverb',
      'präposition' => 'Preposition',
      'konjunktion' => 'Conjunction',
      'interjektion' => 'Interjection',
      'redwendung', 'redewendung' => 'Expression',
      default => $vocabulary['part_of_speech'] ?? '',
    };
    return $vocabulary;
  }

  private function excerpt(string $value, int $length): string {
    return Unicode::truncate(trim(Html::decodeEntities(strip_tags($value))), $length, TRUE, TRUE);
  }

  private function getBlogExcerpt(NodeInterface $node): string {
    if (!$node->hasField('field_blog_body') || $node->get('field_blog_body')->isEmpty()) {
      return '';
    }
    $field = $node->get('field_blog_body');
    return $this->excerpt((string) ($field->summary ?: $field->value), 150);
  }

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
   * @return string[]
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

}
