<?php

namespace Drupal\mahlzeit_consultation\Service;

use Drupal\Core\Database\Connection;

/**
 * Stores and reads consultation requests.
 */
final class ConsultationService {

  /**
   * Constructs the consultation service.
   */
  public function __construct(
    private readonly Connection $database,
  ) {
  }

  /**
   * Stores a submitted consultation request.
   *
   * @param array<string, mixed> $values
   *   Submitted form values.
   *
   * @return int
   *   The new request ID.
   */
  public function saveRequest(array $values): int {
    $now = time();

    return (int) $this->database->insert('mahlzeit_consultation_request')
      ->fields([
        'name' => trim((string) ($values['name'] ?? '')),
        'email' => trim((string) ($values['email'] ?? '')),
        'phone' => trim((string) ($values['phone'] ?? '')),
        'level' => trim((string) ($values['level'] ?? '')),
        'preferred_contact' => trim((string) ($values['preferred_contact'] ?? 'email')),
        'preferred_time' => trim((string) ($values['preferred_time'] ?? '')),
        'message' => trim((string) ($values['message'] ?? '')),
        'status' => 'new',
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Returns the newest consultation requests.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getRequests(int $limit = 100): array {
    $rows = $this->database->select('mahlzeit_consultation_request', 'request')
      ->fields('request')
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();

    return array_map(static fn(object $row): array => [
      'id' => (int) $row->id,
      'name' => (string) $row->name,
      'email' => (string) $row->email,
      'phone' => (string) ($row->phone ?? ''),
      'level' => (string) ($row->level ?? ''),
      'preferred_contact' => (string) ($row->preferred_contact ?? ''),
      'preferred_time' => (string) ($row->preferred_time ?? ''),
      'message' => (string) ($row->message ?? ''),
      'status' => (string) $row->status,
      'created' => (int) $row->created,
      'changed' => (int) $row->changed,
    ], $rows);
  }

}
