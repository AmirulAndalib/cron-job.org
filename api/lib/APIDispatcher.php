<?php
require_once('config/config.inc.php');
require_once('APIMethod.php');
require_once('RateLimiter.php');
require_once('SessionToken.php');
require_once('AbstractDispatcher.php');

class APIDispatcher extends AbstractDispatcher {
  private $refreshTokenHandler = false;
  private $sessionTokenHandler = false;

  public function registerRefreshTokenHandler($handler) {
    $this->refreshTokenHandler = $handler;
  }

  public function registerSessionTokenHandler($handler) {
    $this->sessionTokenHandler = $handler;
  }

  private function authenticate() {
    $headers = apache_request_headers();
    if (!isset($headers['Authorization']) || strpos($headers['Authorization'], ' ') === false) {
      throw new UnauthorizedAPIException('Missing/invalid authorization.');
    }

    [$method, $payload] = explode(' ', $headers['Authorization']);
    if ($method !== 'Bearer') {
      throw new UnauthorizedAPIException('Unsupported authorization method.');
    }

    return SessionToken::fromJwt($payload);
  }

  private function validateSessionToken($sessionToken) {
    if (!$this->sessionTokenHandler) {
      return true;
    }

    return $this->sessionTokenHandler->validateSessionToken($sessionToken);
  }

  private function validateRefreshToken($sessionToken) {
    if (!$this->refreshTokenHandler || !isset($_COOKIE['refreshToken'])) {
      return false;
    }

    $refreshToken = $_COOKIE['refreshToken'];
    if ($this->refreshTokenHandler->validateRefreshToken($refreshToken, intval($sessionToken->userId))) {
      return true;
    }

    return false;
  }

  private function mayRefreshSessionToken($sessionToken) {
    if (!$this->refreshTokenHandler) {
      return false;
    }

    if ($this->refreshTokenHandler->mayRefreshSessionToken(intval($sessionToken->userId))) {
      return true;
    }

    return false;
  }

  public function dispatch() {
    global $config;

    $origin = strtolower(isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '');

    if (in_array($origin, $config['allowCredentialsOrigins'])) {
      header('Access-Control-Allow-Origin: ' . $origin);
      header('Access-Control-Allow-Credentials: true');
    } else {
      //! @todo If we want to scope this down, we need to find a way to allow all the status page domains
      //!       or alternatively proxy the GetPublicStatusPage API from the status page server, altering the
      //!       Access-Control-Allow-Origin header.
      header('Access-Control-Allow-Origin: *');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
      header('HTTP/1.1 204 No Content');
      header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Method, X-UI-Language');
      header('Access-Control-Allow-Methods: POST');
      header('Access-Control-Max-Age: 1800');
      return;
    }

    header('Access-Control-Expose-Headers: X-Refreshed-Token');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('HTTP/1.1 405 Method Not Allowed');
      return;
    }

    if (!isset($_SERVER['HTTP_X_API_METHOD'])) {
      header('HTTP/1.1 400 Bad Request');
      echo('No API method set.');
      return;
    }

    if (!isset($this->handlers[$_SERVER['HTTP_X_API_METHOD']])) {
      header('HTTP/1.1 400 Bad Request');
      echo('Unsupported API method.');
      return;
    }

    if (!isset($_SERVER['HTTP_X_UI_LANGUAGE']) || !in_array($_SERVER['HTTP_X_UI_LANGUAGE'], $config['languages'])) {
      $language = $config['fallbackLanguage'];
    } else {
      $language = $_SERVER['HTTP_X_UI_LANGUAGE'];
    }

    if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
      $request = json_decode(file_get_contents('php://input'));
    } else {
      $request = new stdClass;
    }

    $handler = ($this->handlers[$_SERVER['HTTP_X_API_METHOD']])();

    try {
      $sessionToken = false;
      if ($handler->requiresAuthentication()) {
        try {
          $sessionToken = $this->authenticate();

          if ($sessionToken->isExpired()) {
            if (!$this->validateRefreshToken($sessionToken)) {
              throw new UnauthorizedAPIException();
            }
          }

          if (!$this->validateSessionToken($sessionToken)) {
            throw new UnauthorizedAPIException();
          }
        } catch (Exception $e) {
          throw new UnauthorizedAPIException();
        }
      }

      if (!RateLimiter::check($handler, $request, $sessionToken)) {
        throw new TooManyRequestsAPIException();
      }

      if (!$handler->validateRequest($request)) {
        throw new BadRequestAPIException();
      }

      if ($sessionToken !== false
          && ($sessionToken->expires - $config['sessionTokenLifetime']) <= time() - $config['sessionTokenRefreshInterval']
          && $this->mayRefreshSessionToken($sessionToken)) {
        $sessionToken->refresh();
        header('X-Refreshed-Token: ' . $sessionToken->toJwt());
      }

      $result = $handler->execute($request, $sessionToken, $language);

      header('HTTP/1.1 200 OK');
      header('Content-Type: application/json');

      echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (APIException $e) {
      header('HTTP/1.1 ' . $e->httpStatus());
    } catch (Exception $e) {
      error_log('API exception: ' . (string)$e);

      header('HTTP/1.1 500 Internal Server Error');
    }
  }
}
