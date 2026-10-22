<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Remote;

use KornSW\RemoteArticles\Sources\SecretBox;
use KornSW\RemoteArticles\Sources\Source;

/**
 * Reads JSON from the REST API of a configured source (doc/security.md, section 1).
 *
 * - targets come only from the admin configuration (base URL + fixed REST route);
 * - wp_safe_remote_get() rejects private and reserved address ranges;
 * - https only, certificate verification on, redirects only to the same host;
 * - size and time limits.
 */
final class RemoteClient {

  private const TIMEOUT_SECONDS = 10;
  private const MAXIMUM_RESPONSE_BYTES = 4 * 1024 * 1024;
  private const MAXIMUM_REDIRECTS = 2;

  public function __construct(
    private readonly SecretBox $secretBox
  ) {
  }

  /**
   * Performs a GET request against a REST route of the source and returns the decoded JSON.
   *
   * @param string $route REST route starting with "/", e.g. "/wp/v2/posts".
   * @param array<string, string|int> $query Query parameters.
   * @return array<mixed>
   * @throws RemoteException If the source cannot deliver valid JSON.
   */
  public function getJson(Source $source, string $route, array $query): array {
    $prettyUrl = $source->baseUrl . '/wp-json' . $route;
    if (count($query) > 0) {
      $prettyUrl .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $response = $this->request($source, $prettyUrl, $this->buildRequestArguments($source));
    if ($response['status'] === 404 && $this->decodeJson($response['body']) === null) {
      // Installations without pretty permalinks only answer on "?rest_route=".
      $plainUrl = $source->baseUrl . '/?' . http_build_query(['rest_route' => $route] + $query, '', '&', PHP_QUERY_RFC3986);
      $response = $this->request($source, $plainUrl, $this->buildRequestArguments($source));
    }

    if ($response['status'] !== 200) {
      throw new RemoteException(sprintf('Die Quelle „%s“ antwortet mit HTTP-Status %d.', $source->alias, $response['status']));
    }
    $data = $this->decodeJson($response['body']);
    if ($data === null) {
      throw new RemoteException(sprintf('Die Quelle „%s“ liefert keine gültigen Daten (keine WordPress REST API?).', $source->alias));
    }
    return $data;
  }

  /**
   * Reads an HTML page of the source as an anonymous visitor (e.g. to read values a plugin of the source prints into its
   * pages). Only URLs on the host of the source; stored credentials are never sent.
   *
   * @throws RemoteException
   */
  public function getPage(Source $source, string $url): string {
    $this->assertSourceUrl($source, $url);
    $response = $this->request($source, $url, $this->buildAnonymousArguments('text/html'));
    if ($response['status'] !== 200) {
      throw new RemoteException(sprintf('Die Seite der Quelle „%s“ antwortet mit HTTP-Status %d.', $source->alias, $response['status']));
    }
    return $response['body'];
  }

  /**
   * Posts a form to admin-ajax.php of the source - the only POST target - as an anonymous visitor of $refererUrl.
   * Plugins of the source may reject requests without a referer of their own site. Some of them are slow (e.g. when they
   * rebuild their own cache), so callers may allow a longer timeout.
   *
   * @param array<string, string> $fields
   * @throws RemoteException
   */
  public function postAdminAjax(Source $source, array $fields, string $refererUrl, int $timeoutSeconds = self::TIMEOUT_SECONDS): string {
    $this->assertSourceUrl($source, $refererUrl);
    $arguments = $this->buildAnonymousArguments('application/json');
    $arguments['method'] = 'POST';
    $arguments['body'] = $fields;
    $arguments['headers']['Referer'] = $refererUrl;
    $arguments['timeout'] = $timeoutSeconds;

    $response = wp_safe_remote_request($source->baseUrl . '/wp-admin/admin-ajax.php', $arguments);
    if (is_wp_error($response)) {
      throw new RemoteException(sprintf('Die Quelle „%s“ ist nicht erreichbar: %s', $source->alias, $response->get_error_message()));
    }
    $status = (int)wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
      throw new RemoteException(sprintf('Die Quelle „%s“ antwortet mit HTTP-Status %d.', $source->alias, $status));
    }
    return (string)wp_remote_retrieve_body($response);
  }

  /**
   * Requests a URL of the source without referer and returns where it redirects to (without following), or null.
   *
   * @throws RemoteException
   */
  public function getRedirectTarget(Source $source, string $url, int $timeoutSeconds = self::TIMEOUT_SECONDS): ?string {
    $this->assertSourceUrl($source, $url);
    $arguments = $this->buildAnonymousArguments('*/*');
    $arguments['limit_response_size'] = 64 * 1024;
    $arguments['timeout'] = $timeoutSeconds;
    $response = wp_safe_remote_get($url, $arguments);
    if (is_wp_error($response)) {
      throw new RemoteException(sprintf('Die Quelle „%s“ ist nicht erreichbar: %s', $source->alias, $response->get_error_message()));
    }
    $status = (int)wp_remote_retrieve_response_code($response);
    if ($status < 300 || $status >= 400) {
      return null;
    }
    $location = (string)wp_remote_retrieve_header($response, 'location');
    if ($location === '') {
      return null;
    }
    return $location;
  }

  /**
   * Sends the request and follows redirects that stay on the host of the source.
   *
   * @param array<string, mixed> $arguments Arguments of wp_safe_remote_get().
   * @return array{status: int, body: string}
   */
  private function request(Source $source, string $url, array $arguments): array {
    $currentUrl = $url;

    for ($redirectCount = 0; $redirectCount <= self::MAXIMUM_REDIRECTS; $redirectCount++) {
      $response = wp_safe_remote_get($currentUrl, $arguments);
      if (is_wp_error($response)) {
        throw new RemoteException(sprintf('Die Quelle „%s“ ist nicht erreichbar: %s', $source->alias, $response->get_error_message()));
      }

      $status = (int)wp_remote_retrieve_response_code($response);
      if ($status < 300 || $status >= 400) {
        return [
          'status' => $status,
          'body' => (string)wp_remote_retrieve_body($response),
        ];
      }

      $location = (string)wp_remote_retrieve_header($response, 'location');
      $redirectUrl = $this->resolveSameHostRedirect($source, $location);
      if ($redirectUrl === null) {
        throw new RemoteException(sprintf('Die Quelle „%s“ leitet auf eine andere Adresse um; das ist aus Sicherheitsgründen nicht erlaubt.', $source->alias));
      }
      $currentUrl = $redirectUrl;
    }

    throw new RemoteException(sprintf('Die Quelle „%s“ leitet zu oft um.', $source->alias));
  }

  /**
   * Builds the arguments of wp_safe_remote_get(); stored credentials are attached only here (server-side reading).
   *
   * @return array<string, mixed>
   */
  private function buildRequestArguments(Source $source): array {
    $headers = [
      'Accept' => 'application/json',
    ];
    if ($source->hasCredentials()) {
      $password = $this->secretBox->decrypt($source->encryptedPassword);
      if ($password !== null) {
        $headers['Authorization'] = 'Basic ' . base64_encode($source->userName . ':' . $password);
      }
    }

    return [
      'timeout' => self::TIMEOUT_SECONDS,
      'redirection' => 0,
      'limit_response_size' => self::MAXIMUM_RESPONSE_BYTES,
      'sslverify' => true,
      'headers' => $headers,
      'user-agent' => 'KornSW-RemoteArticles/' . KORNSW_RA_VERSION . '; ' . home_url('/'),
    ];
  }

  /**
   * Arguments for requests as an anonymous visitor (no stored credentials).
   *
   * @return array<string, mixed>
   */
  private function buildAnonymousArguments(string $accept): array {
    return [
      'timeout' => self::TIMEOUT_SECONDS,
      'redirection' => 0,
      'limit_response_size' => self::MAXIMUM_RESPONSE_BYTES,
      'sslverify' => true,
      'headers' => ['Accept' => $accept],
      'user-agent' => 'Mozilla/5.0 (compatible; KornSW-RemoteArticles/' . KORNSW_RA_VERSION . ')',
    ];
  }

  /**
   * Rejects every URL that is not https on the host of the source.
   *
   * @throws RemoteException
   */
  private function assertSourceUrl(Source $source, string $url): void {
    $parts = parse_url($url);
    $isSourceUrl = is_array($parts)
      && isset($parts['scheme'], $parts['host'])
      && strtolower($parts['scheme']) === 'https'
      && strtolower($parts['host']) === $source->getHost()
      && !isset($parts['user'])
      && !isset($parts['pass']);
    if (!$isSourceUrl) {
      throw new RemoteException(sprintf('Die Adresse gehört nicht zur Quelle „%s“.', $source->alias));
    }
  }

  /**
   * Accepts a redirect only if it stays on https and on the host of the source.
   */
  private function resolveSameHostRedirect(Source $source, string $location): ?string {
    if ($location === '') {
      return null;
    }

    $redirectUrl = $location;
    if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
      $redirectUrl = 'https://' . $source->getHost() . $location;
    }

    $parts = parse_url($redirectUrl);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return null;
    }
    if (strtolower($parts['scheme']) !== 'https' || strtolower($parts['host']) !== $source->getHost()) {
      return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return null;
    }
    return $redirectUrl;
  }

  /**
   * Decodes a JSON object or array; returns null for anything else.
   *
   * @return array<mixed>|null
   */
  private function decodeJson(string $body): ?array {
    try {
      $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      return null;
    }
    if (!is_array($data)) {
      return null;
    }
    return $data;
  }

}
