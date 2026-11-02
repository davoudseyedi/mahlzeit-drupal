<?php

namespace Drupal\mahlzeit_vocabulary\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores, serves and synchronizes German vocabulary items.
 */
final class VocabularyService {

  private const DUDEN_URL = 'https://www.duden.de/wort-des-tages';

  private const SYNC_INTERVAL = 86400;

  private readonly LoggerInterface $logger;

  /**
   * Constructs the vocabulary service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    $this->logger = $loggerFactory->get('mahlzeit_vocabulary');
  }

  /**
   * Returns all active entries in their learning order.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getActiveVocabulary(): array {
    $rows = $this->database->select('mahlzeit_vocabulary_item', 'vocabulary')
      ->fields('vocabulary')
      ->condition('is_active', 1)
      ->orderBy('sort_order', 'ASC')
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAll();

    return array_map(fn(object $row): array => $this->normalizeRow($row), $rows);
  }

  /**
   * Returns the first active entry as today's vocabulary.
   *
   * @return array<string, mixed>|null
   */
  public function getTodayVocabulary(): ?array {
    $items = $this->getActiveVocabulary();
    return $items[0] ?? NULL;
  }

  /**
   * Returns all entries for the administration screen.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAllVocabulary(): array {
    $rows = $this->database->select('mahlzeit_vocabulary_item', 'vocabulary')
      ->fields('vocabulary')
      ->orderBy('sort_order', 'ASC')
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAll();

    return array_map(fn(object $row): array => $this->normalizeRow($row), $rows);
  }

  /**
   * Returns one entry by ID.
   *
   * @return array<string, mixed>|null
   */
  public function getVocabulary(int $id): ?array {
    $row = $this->database->select('mahlzeit_vocabulary_item', 'vocabulary')
      ->fields('vocabulary')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    return $row ? $this->normalizeRow($row) : NULL;
  }

  /**
   * Saves an authored vocabulary item.
   *
   * @param array<string, mixed> $values
   */
  public function save(array $values, ?int $id = NULL): int {
    $title = trim((string) ($values['title'] ?? ''));
    if ($title === '') {
      throw new \InvalidArgumentException('A vocabulary title is required.');
    }

    $fields = [
      'level' => trim((string) ($values['level'] ?? 'ALL')) ?: 'ALL',
      'title' => $this->clip($title, 255),
      'part_of_speech' => $this->clip(trim((string) ($values['part_of_speech'] ?? '')), 80),
      'description' => trim((string) ($values['description'] ?? '')),
      'pronunciation' => trim((string) ($values['pronunciation'] ?? '')),
      'is_active' => empty($values['is_active']) ? 0 : 1,
      'sort_order' => (int) ($values['sort_order'] ?? 0),
      'published_date' => $this->normalizeDate($values['published_date'] ?? ''),
      'changed' => time(),
    ];

    if ($id !== NULL) {
      $this->database->update('mahlzeit_vocabulary_item')
        ->fields($fields)
        ->condition('id', $id)
        ->execute();
      $this->invalidateCache();
      return $id;
    }

    $fields['created'] = time();
    $newId = (int) $this->database->insert('mahlzeit_vocabulary_item')
      ->fields($fields)
      ->execute();
    $this->invalidateCache();
    return $newId;
  }

  /**
   * Deletes an entry.
   */
  public function delete(int $id): void {
    $this->database->delete('mahlzeit_vocabulary_item')
      ->condition('id', $id)
      ->execute();
    $this->invalidateCache();
  }

  /**
   * Imports Duden's current word of the day.
   *
   * @param bool $force
   *   Ignore the once-per-day throttle.
   *
   * @return array<string, mixed>|null
   *   The imported item, or NULL when a throttled cron run had nothing to do.
   */
  public function syncDuden(bool $force = FALSE): ?array {
    $lastSync = (int) $this->state->get('mahlzeit_vocabulary.last_duden_sync', 0);
    if (!$force && $lastSync > time() - self::SYNC_INTERVAL) {
      return NULL;
    }

    try {
      $page = $this->httpClient->request('GET', self::DUDEN_URL, [
        'headers' => [
          'User-Agent' => 'Mahlzeit Vocabulary/1.0 (+https://mahlzeitlab.com)',
          'Accept' => 'text/html,application/xhtml+xml',
        ],
        'timeout' => 15,
      ])->getBody()->getContents();

      $parsed = $this->parseDudenPage($page);
      $existing = $this->database->select('mahlzeit_vocabulary_item', 'vocabulary')
        ->fields('vocabulary')
        ->condition('title', $parsed['title'])
        ->execute()
        ->fetchObject();

      $fields = [
        'part_of_speech' => $parsed['part_of_speech'],
        'description' => $parsed['description'],
        'published_date' => date('Y-m-d'),
        'changed' => time(),
      ];

      if ($existing) {
        $this->database->update('mahlzeit_vocabulary_item')
          ->fields($fields)
          ->condition('id', (int) $existing->id)
          ->execute();
        $id = (int) $existing->id;
      }
      else {
        $id = (int) $this->database->insert('mahlzeit_vocabulary_item')
          ->fields([
            'level' => 'ALL',
            'title' => $parsed['title'],
            'part_of_speech' => $parsed['part_of_speech'],
            'description' => $parsed['description'],
            'pronunciation' => '',
            'is_active' => 1,
            'sort_order' => 0,
            'published_date' => date('Y-m-d'),
            'created' => time(),
            'changed' => time(),
          ])
          ->execute();
      }

      $this->state->set('mahlzeit_vocabulary.last_duden_sync', time());
      $this->invalidateCache();
      $this->logger->info('Duden vocabulary synchronized: @title.', ['@title' => $parsed['title']]);
      return $this->getVocabulary($id);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Duden vocabulary synchronization failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      if ($force) {
        throw $exception;
      }
      return NULL;
    }
  }

  /**
   * Parses the current Duden word page and its detail page.
   *
   * @return array{title: string, part_of_speech: string, description: string}
   */
  private function parseDudenPage(string $page): array {
    $match = [];
    if (!preg_match('/<h2[^>]*class="[^"]*scene__title[^"]*"[^>]*>\s*<a[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i', $page, $match, PREG_OFFSET_CAPTURE)) {
      throw new \RuntimeException('Duden word could not be extracted.');
    }

    $title = $this->clip($this->cleanText($match[2][0]), 255);
    $wordUrl = $match[1][0];
    if (str_starts_with($wordUrl, '//')) {
      $wordUrl = 'https:' . $wordUrl;
    }
    elseif (str_starts_with($wordUrl, '/')) {
      $wordUrl = 'https://www.duden.de' . $wordUrl;
    }

    $nearby = substr($page, (int) $match[0][1], 1800);
    preg_match('/class="[^"]*scene__main[^"]*"[^>]*>\s*([^<]+)/i', $nearby, $typeMatch);
    $partOfSpeech = $this->cleanText($typeMatch[1] ?? 'Wortart nicht angegeben');
    $description = 'Wort des Tages von Duden';

    try {
      $detail = $this->httpClient->request('GET', $wordUrl, [
        'headers' => ['User-Agent' => 'Mahlzeit Vocabulary/1.0 (+https://mahlzeitlab.com)'],
        'timeout' => 15,
      ])->getBody()->getContents();
      $detailText = $this->cleanText($detail);
      if (preg_match('/Wortart\s*:\s*(Substantiv|Verb|Adjektiv|Adverb|Pronomen|Präposition|Konjunktion|Interjektion)/i', $detailText, $detailType)) {
        $partOfSpeech = $detailType[1];
      }

      $meaningIndex = -1;
      $meaningMatch = [];
      if (preg_match('/>\s*Bedeutung(?:en)?\s*</i', $detail, $meaningMatch, PREG_OFFSET_CAPTURE)) {
        $meaningIndex = (int) $meaningMatch[0][1];
      }
      $meaningPart = $meaningIndex >= 0 ? substr($detail, $meaningIndex, 7000) : '';
      $cleaned = $this->cleanText($meaningPart);
      $cleaned = preg_replace('/^>\s*/', '', $cleaned) ?? $cleaned;
      $cleaned = preg_replace('/^Bedeutung(?:en)?\s*(?:ⓘ\s*)?/i', '', $cleaned) ?? $cleaned;
      $cleaned = preg_replace('/^[:\-–]\s*/', '', $cleaned) ?? $cleaned;
      $cleaned = preg_split('/Anzeige|Grammatik|Aussprache/i', $cleaned)[0] ?? $cleaned;
      $cleaned = trim($cleaned);
      if ($cleaned !== '') {
        $description = $this->clip($cleaned, 500);
      }
    }
    catch (\Throwable $exception) {
      // The word itself remains useful when Duden's detail page is unavailable.
      $this->logger->notice('Duden detail page unavailable for @title: @message', [
        '@title' => $title,
        '@message' => $exception->getMessage(),
      ]);
    }

    return [
      'title' => $title,
      'part_of_speech' => $partOfSpeech ?: 'Wortart nicht angegeben',
      'description' => $description,
    ];
  }

  /**
   * Normalizes a database row for controllers and Twig.
   *
   * @return array<string, mixed>
   */
  private function normalizeRow(object $row): array {
    return [
      'id' => (int) $row->id,
      'level' => (string) $row->level,
      'title' => (string) $row->title,
      'part_of_speech' => $this->translatePartOfSpeech((string) ($row->part_of_speech ?? '')),
      'description' => (string) ($row->description ?? ''),
      'pronunciation' => (string) ($row->pronunciation ?? ''),
      'is_active' => (bool) $row->is_active,
      'sort_order' => (int) $row->sort_order,
      'published_date' => (string) ($row->published_date ?? ''),
      'created' => (int) $row->created,
      'changed' => (int) $row->changed,
    ];
  }

  /**
   * Presents common German grammar labels in English in the public UI.
   */
  private function translatePartOfSpeech(string $part): string {
    return match (mb_strtolower(trim($part))) {
      'pronomen' => 'Pronoun',
      'substantiv', 'nomen' => 'Noun',
      'adjektiv' => 'Adjective',
      'adverb' => 'Adverb',
      'präposition' => 'Preposition',
      'konjunktion' => 'Conjunction',
      'interjektion' => 'Interjection',
      'wortart nicht angegeben' => 'Part of speech not specified',
      default => $part,
    };
  }

  /**
   * Removes markup/entities and collapses whitespace from imported text.
   */
  private function cleanText(string $value): string {
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\u{00AD}", '', $value);
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Clips UTF-8 text without cutting a word unnecessarily.
   */
  private function clip(string $value, int $length): string {
    if (function_exists('mb_substr')) {
      return mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
  }

  /**
   * Keeps authored dates in the database's YYYY-MM-DD format.
   */
  private function normalizeDate(mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') {
      return date('Y-m-d');
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('Y-m-d') : date('Y-m-d');
  }

  /**
   * Invalidates public pages that expose the current vocabulary item.
   */
  private function invalidateCache(): void {
    $this->cacheTagsInvalidator->invalidateTags(['mahlzeit_vocabulary']);
  }

}
