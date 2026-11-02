<?php

namespace Drupal\mahlzeit_news\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports and serves Tagesschau news for German learners.
 */
final class NewsService {

  /** @var string[] */
  private const CATEGORIES = [
    'inland' => 'Domestic',
    'innenpolitik' => 'Politics',
    'gesellschaft' => 'Society',
    'regional' => 'Regional',
    'deutschland-trend' => 'Germany trend',
  ];

  /** @var string[] */
  private const FEEDS = [
    'inland' => '/inland/index~rss2.xml',
    'innenpolitik' => '/inland/innenpolitik/index~rss2.xml',
    'gesellschaft' => '/inland/gesellschaft/index~rss2.xml',
    'regional' => '/inland/regional/index~rss2.xml',
    'deutschland-trend' => '/inland/deutschlandtrend/index~rss2.xml',
  ];

  private const FEED_BASE = 'https://www.tagesschau.de';
  private const MAX_ITEMS_PER_FEED = 12;
  private const SYNC_INTERVAL = 21600;
  private const ENRICH_INTERVAL = 21600;

  private bool $syncing = FALSE;

  private readonly LoggerInterface $logger;

  /**
   * Constructs the news service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('mahlzeit_news');
  }

  /**
   * Returns the configured category labels.
   *
   * @return string[]
   */
  public function getCategories(): array {
    return self::CATEGORIES;
  }

  /**
   * Checks whether a category is supported.
   */
  public function isValidCategory(?string $category): bool {
    return $category === NULL || $category === '' || isset(self::CATEGORIES[$category]);
  }

  /**
   * Checks whether a date filter is supported.
   */
  public function isValidDateFilter(?string $filter): bool {
    return in_array($filter, ['all', 'today', '7d', '30d'], TRUE);
  }

  /**
   * Returns news items, importing the first batch when the cache is empty.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getNews(?string $category = NULL, string $date_filter = 'all', int $limit = 12): array {
    if (!$this->hasItems($category)) {
      // The first request should still have useful content even before cron
      // has run. This path only performs the fast RSS import; media and AI
      // enrichment are left for cron or the admin sync form.
      $this->sync(FALSE, FALSE);
    }

    $query = $this->database->select('mahlzeit_news_item', 'news');
    $query->fields('news');
    if ($category) {
      $query->condition('category', $category);
    }
    $cutoff = $this->getCutoff($date_filter);
    if ($cutoff !== NULL) {
      $query->condition('published_at', $cutoff, '>=');
    }
    $query->orderBy('published_at', 'DESC');
    $query->orderBy('id', 'DESC');
    $query->extend('Drupal\\Core\\Database\\Query\\PagerSelectExtender')->limit($limit);

    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = $this->normalizeRow($row);
    }
    return $items;
  }

  /**
   * Returns one stored news item or NULL.
   *
   * @return array<string, mixed>|null
   */
  public function getNewsItem(int $id): ?array {
    $row = $this->database->select('mahlzeit_news_item', 'news')
      ->fields('news')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    return $row ? $this->normalizeRow($row) : NULL;
  }

  /**
   * Returns one stored news item by its human-readable machine name.
   *
   * The source feed already provides stable article slugs in its URL. We use
   * that slug instead of exposing the internal numeric database ID publicly.
   *
   * @return array<string, mixed>|null
   */
  public function getNewsItemByMachineName(string $machine_name): ?array {
    $machine_name = strtolower(trim($machine_name));
    if ($machine_name === '') {
      return NULL;
    }

    $rows = $this->database->select('mahlzeit_news_item', 'news')
      ->fields('news')
      ->execute();
    foreach ($rows as $row) {
      $item = $this->normalizeRow($row);
      if ($item['machine_name'] === $machine_name) {
        return $item;
      }
    }

    return NULL;
  }

  /**
   * Synchronizes all configured RSS feeds.
   *
   * @param bool $force
   *   Ignore the six-hour throttle.
   * @param bool $enrich
   *   Also try to discover media and optional AI vocabulary.
   *
   * @return int
   *   Number of feed items imported or updated.
   */
  public function sync(bool $force = FALSE, bool $enrich = TRUE): int {
    if ($this->syncing) {
      return 0;
    }

    $last_sync = (int) $this->state->get('mahlzeit_news.last_sync', 0);
    if (!$force && $last_sync > (time() - self::SYNC_INTERVAL) && $this->hasItems()) {
      if ($enrich) {
        $this->enrichPending();
      }
      return 0;
    }

    $this->syncing = TRUE;
    $count = 0;
    try {
      foreach (self::FEEDS as $category => $feed_path) {
        try {
          foreach ($this->fetchFeed($category, $feed_path) as $item) {
            $this->upsert($item);
            $count++;
          }
        }
        catch (\Throwable $exception) {
          $this->logger->warning('News feed @category failed: @message', [
            '@category' => $category,
            '@message' => $exception->getMessage(),
          ]);
        }
      }

      if ($count > 0) {
        $this->state->set('mahlzeit_news.last_sync', time());
      }
      if ($enrich) {
        $this->enrichPending($force);
      }
      $this->logger->info('News synchronized: @count items.', ['@count' => $count]);
    }
    finally {
      $this->syncing = FALSE;
    }

    return $count;
  }

  /**
   * Enriches cached items independently from the RSS synchronization window.
   *
   * The app implementation performs this work in the background after the
   * fast RSS response. Drupal's cron is the equivalent background worker, so
   * it must still run when the RSS cache itself is fresh.
   */
  private function enrichPending(bool $force = FALSE): void {
    $last_enrichment = (int) $this->state->get('mahlzeit_news.last_enrichment', 0);
    if (!$force && $last_enrichment > (time() - self::ENRICH_INTERVAL)) {
      return;
    }

    $this->enrichMedia(12);
    $this->enrichImportantWords();
    $this->state->set('mahlzeit_news.last_enrichment', time());
  }

  /**
   * Returns a human-readable category label.
   */
  public function getCategoryLabel(string $category): string {
    return self::CATEGORIES[$category] ?? $category;
  }

  /**
   * Checks whether at least one cached item exists.
   */
  private function hasItems(?string $category = NULL): bool {
    $query = $this->database->select('mahlzeit_news_item', 'news');
    $query->addExpression('1');
    $query->range(0, 1);
    if ($category) {
      $query->condition('category', $category);
    }
    return (bool) $query->execute()->fetchField();
  }

  /**
   * Calculates the start timestamp for a date filter.
   */
  private function getCutoff(string $date_filter): ?int {
    return match ($date_filter) {
      'today' => strtotime('today'),
      '7d' => strtotime('-6 days', strtotime('today')),
      '30d' => strtotime('-29 days', strtotime('today')),
      default => NULL,
    };
  }

  /**
   * Fetches and parses one RSS feed.
   *
   * @return array<int, array<string, mixed>>
   */
  private function fetchFeed(string $category, string $feed_path): array {
    $response = $this->httpClient->request('GET', self::FEED_BASE . $feed_path, [
      'timeout' => 15,
      'connect_timeout' => 5,
      'headers' => [
        'Accept' => 'application/rss+xml, application/xml, text/xml',
        'User-Agent' => 'Mahlzeit News importer/1.0',
      ],
    ]);
    $body = (string) $response->getBody();
    $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if (!$xml) {
      throw new \RuntimeException('The RSS response was not valid XML.');
    }

    $items = [];
    foreach (($xml->channel->item ?? []) as $entry) {
      $title = $this->cleanText((string) ($entry->title ?? ''));
      $url = $this->normalizeTrustedUrl((string) ($entry->link ?? ''));
      if ($title === '' || $url === NULL) {
        continue;
      }

      $published = strtotime((string) ($entry->pubDate ?? $entry->published ?? '')) ?: time();
      $video_url = NULL;
      if (isset($entry->enclosure['url'])) {
        $video_url = $this->normalizeMediaUrl((string) $entry->enclosure['url']);
      }

      $items[] = [
        'category' => $category,
        'title' => mb_substr($title, 0, 255),
        'published' => $published,
        'description' => $this->cleanText((string) ($entry->description ?? '')),
        'source_url' => $url,
        'video_url' => $video_url,
        'audio_url' => NULL,
      ];

      if (count($items) >= self::MAX_ITEMS_PER_FEED) {
        break;
      }
    }

    return $items;
  }

  /**
   * Inserts or updates one item by its source URL.
   *
   * @param array<string, mixed> $item
   *   Normalized remote item.
   */
  private function upsert(array $item): void {
    $now = time();
    $existing_id = $this->database->select('mahlzeit_news_item', 'news')
      ->fields('news', ['id'])
      ->condition('source_url', $item['source_url'])
      ->execute()
      ->fetchField();

    $fields = [
      'category' => $item['category'],
      'title' => $item['title'],
      'published_at' => $item['published'],
      'description' => $item['description'],
      'source_url' => $item['source_url'],
      'changed' => $now,
    ];

    if ($existing_id) {
      // RSS does not always expose media URLs. Keep URLs discovered on a
      // previous enrichment run instead of replacing them with NULL.
      if ($item['video_url']) {
        $fields['video_url'] = $item['video_url'];
      }
      if ($item['audio_url']) {
        $fields['audio_url'] = $item['audio_url'];
      }
      $this->database->update('mahlzeit_news_item')
        ->fields($fields)
        ->condition('id', $existing_id)
        ->execute();
      return;
    }

    $this->database->insert('mahlzeit_news_item')
      ->fields($fields + [
        'video_url' => $item['video_url'],
        'audio_url' => $item['audio_url'],
        'important_words' => json_encode([], JSON_UNESCAPED_UNICODE),
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Finds video/audio URLs on a small number of source pages.
   */
  private function enrichMedia(int $limit): void {
    $rows = $this->database->select('mahlzeit_news_item', 'news')
      ->fields('news', ['id', 'source_url'])
      ->isNull('video_url')
      ->isNull('audio_url')
      ->orderBy('published_at', 'DESC')
      ->range(0, $limit)
      ->execute();

    foreach ($rows as $row) {
      try {
        $media = $this->findMedia((string) $row->source_url);
        if ($media['video_url'] || $media['audio_url']) {
          $this->database->update('mahlzeit_news_item')
            ->fields($media + ['changed' => time()])
            ->condition('id', (int) $row->id)
            ->execute();
        }
      }
      catch (\Throwable $exception) {
        $this->logger->debug('Media discovery failed for @url: @message', [
          '@url' => $row->source_url,
          '@message' => $exception->getMessage(),
        ]);
      }
    }
  }

  /**
   * Discovers media URLs embedded in a Tagesschau article.
   *
   * @return array{video_url: string|null, audio_url: string|null}
   */
  private function findMedia(string $url): array {
    $response = $this->httpClient->request('GET', $url, [
      'timeout' => 12,
      'connect_timeout' => 5,
      'headers' => ['User-Agent' => 'Mahlzeit News importer/1.0'],
    ]);
    $html = html_entity_decode((string) $response->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace(['\\/', '\\"'], ['/', '"'], $html);
    preg_match_all('/https?:[^"\'\\s<>]+\.(?:mp4|m3u8|mp3)(?:\?[^"\'\\s<>]*)?/i', $html, $matches);

    $video_url = NULL;
    $audio_url = NULL;
    foreach ($matches[0] ?? [] as $candidate) {
      $candidate = $this->normalizeMediaUrl($candidate);
      if ($candidate === NULL) {
        continue;
      }
      if (preg_match('/\.(?:mp4|m3u8)(?:\?|$)/i', $candidate)) {
        $video_url = $candidate;
        break;
      }
      if (preg_match('/\.mp3(?:\?|$)/i', $candidate)) {
        $audio_url = $candidate;
      }
    }

    return ['video_url' => $video_url, 'audio_url' => $video_url ? NULL : $audio_url];
  }

  /**
   * Optionally asks OpenAI for learner-friendly words from recent items.
   *
   * This remains a no-op unless OPENAI_API_KEY (or the equivalent Drupal
   * setting) is configured, so RSS news works without an AI dependency.
   */
  private function enrichImportantWords(): void {
    $api_key = (string) Settings::get('mahlzeit_news_openai_api_key', getenv('OPENAI_API_KEY') ?: '');
    if ($api_key === '') {
      return;
    }

    $candidates = [];
    $rows = $this->database->select('mahlzeit_news_item', 'news')
      ->fields('news', ['id', 'title', 'description', 'important_words'])
      ->orderBy('published_at', 'DESC')
      ->range(0, 30)
      ->execute();
    foreach ($rows as $row) {
      $words = json_decode((string) $row->important_words, TRUE);
      if (is_array($words) && $words) {
        continue;
      }
      $candidates[] = [
        'id' => (string) $row->id,
        'title' => mb_substr((string) $row->title, 0, 240),
        'description' => mb_substr((string) $row->description, 0, 1600),
      ];
    }

    foreach (array_chunk($candidates, 6) as $batch) {
      try {
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
          'timeout' => 35,
          'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
          ],
          'json' => [
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'max_tokens' => 3000,
            'messages' => [
              [
                'role' => 'system',
                'content' => 'Du bist ein Deutschlehrer. Wähle aus jeder Nachricht höchstens 8 wichtige deutsche Wörter oder Ausdrücke für Deutschlernende. Gib für jedes Wort eine kurze, einfache Erklärung auf Deutsch im Kontext der Nachricht. Keine Eigennamen, keine Füllwörter. Antworte ausschließlich als JSON im Format {"items":[{"id":"...","words":[{"word":"...","explanation":"..."}]}]}.',
              ],
              [
                'role' => 'user',
                'content' => json_encode($batch, JSON_UNESCAPED_UNICODE),
              ],
            ],
          ],
        ]);
        $payload = json_decode((string) $response->getBody(), TRUE);
        $content = $payload['choices'][0]['message']['content'] ?? '';
        $decoded = is_string($content) ? json_decode($content, TRUE) : NULL;
        foreach (($decoded['items'] ?? []) as $result) {
          if (!is_array($result) || !isset($result['id']) || !is_array($result['words'] ?? NULL)) {
            continue;
          }
          $words = [];
          foreach (array_slice($result['words'], 0, 8) as $word) {
            if (is_array($word) && !empty($word['word']) && !empty($word['explanation'])) {
              $words[] = [
                'word' => (string) $word['word'],
                'explanation' => (string) $word['explanation'],
              ];
            }
          }
          if ($words) {
            $this->database->update('mahlzeit_news_item')
              ->fields([
                'important_words' => json_encode($words, JSON_UNESCAPED_UNICODE),
                'changed' => time(),
              ])
              ->condition('id', (int) $result['id'])
              ->execute();
          }
        }
      }
      catch (\Throwable $exception) {
        $this->logger->warning('News vocabulary enrichment failed: @message', ['@message' => $exception->getMessage()]);
      }
    }
  }

  /**
   * Converts a database row to the stable template/API shape.
   *
   * @return array<string, mixed>
   */
  private function normalizeRow(\stdClass $row): array {
    $words = json_decode((string) ($row->important_words ?? ''), TRUE);
    if (!is_array($words)) {
      $words = [];
    }

    return [
      'id' => (int) $row->id,
      'machine_name' => $this->buildMachineName((string) $row->source_url, (string) $row->title, (int) $row->id),
      'category' => (string) $row->category,
      'title' => (string) $row->title,
      'published' => (int) ($row->published_at ?: $row->created),
      'created' => (int) $row->created,
      'description' => (string) ($row->description ?? ''),
      'url' => (string) $row->source_url,
      'video_url' => $row->video_url ? (string) $row->video_url : NULL,
      'audio_url' => $row->audio_url ? (string) $row->audio_url : NULL,
      'important_words' => array_values($words),
      'tone' => ['green', 'yellow', 'red'][(int) $row->id % 3],
    ];
  }

  /**
   * Builds a stable, URL-safe machine name for a news item.
   */
  private function buildMachineName(string $source_url, string $title, int $id): string {
    $path = (string) parse_url($source_url, PHP_URL_PATH);
    $value = pathinfo($path, PATHINFO_FILENAME);
    $value = $value !== '' && $value !== '.' ? $value : $title;

    if (function_exists('iconv')) {
      $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
      if ($transliterated !== FALSE) {
        $value = $transliterated;
      }
    }

    $value = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value));
    $value = trim($value, '-');

    return substr($value !== '' ? $value : 'news-' . $id, 0, 255);
  }

  /**
   * Cleans RSS/HTML text for safe plain-text display.
   */
  private function cleanText(string $value): string {
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Allows only Tagesschau article URLs.
   */
  private function normalizeTrustedUrl(string $url): ?string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || !preg_match('/(^|\.)tagesschau\.de$/', $host)) {
      return NULL;
    }
    return $url;
  }

  /**
   * Allows media URLs from Tagesschau and the ARD regional media CDN.
   */
  private function normalizeMediaUrl(string $url): ?string {
    $url = trim(html_entity_decode(str_replace('\\/', '/', $url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $is_trusted_media_host = (bool) preg_match('/(^|\.)tagesschau\.de$|(^|\.)ard-mcdn\.de$|(^|\.)ardmediathek\.de$/', $host);
    if (!in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || !$is_trusted_media_host) {
      return NULL;
    }
    if (!preg_match('/\.(?:mp4|m3u8|mp3)(?:\?|$)/i', $url)) {
      return NULL;
    }
    return $url;
  }

}
