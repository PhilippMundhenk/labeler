<?php

declare(strict_types=1);

namespace Labeler;

use InvalidArgumentException;

/**
 * JSON API behind public/api.php. Independent of PHP's request globals so it can be tested.
 */
final class Api
{
    private const FONT_TYPES = [
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    private Config $config;
    private PtouchPrint $printer;
    private Log $log;
    private ?Remote $remote;
    private Session $session;
    private string $serial = '';
    /** Delay after a wrong password, against guessing; tests set it to 0. */
    public static int $loginDelayMs = 1000;

    public function __construct(Config $config, ?PtouchPrint $printer = null, ?Log $log = null)
    {
        $this->config = $config;
        $this->log = $log ?? new Log((string) $config->get('data_dir'), (int) $config->get('log_entries'));
        $this->printer = $printer ?? PtouchPrint::fromConfig($config, $this->log);
        $url = (string) $config->get('printer_url');
        $this->remote = $url === '' ? null : new Remote($url, (string) $config->get('printer_token'), (int) $config->get('timeout'));
        $this->session = new Session((string) $config->get('password'), (string) $config->get('data_dir'));
    }

    /**
     * @param array<string, string> $query
     * @param string $authorization value of the Authorization header
     * @param array<string, string> $cookies
     * @param bool $secure the request came in over HTTPS
     * @return Response
     */
    public function handle(string $method, array $query, string $body, string $contentType, string $authorization = '', array $cookies = [], bool $secure = false): Response
    {
        $action = $query['action'] ?? '';
        $routes = [
            'health' => ['GET', 'health'],
            'status' => ['GET', 'status'],
            'print' => ['POST', 'print'],
            'fonts' => ['GET', 'fonts'],
            'font' => ['GET', 'font'],
            'log' => ['GET', 'log'],
            'printers' => ['GET', 'printers'],
            'login' => ['POST', 'login'],
            'labels' => ['GET', 'labels'],
            'label-save' => ['POST', 'labelSave'],
            'label-delete' => ['POST', 'labelDelete'],
            'logout' => ['POST', 'logout'],
        ];
        if (!isset($routes[$action])) {
            return Response::json(404, ['error' => 'unknown action']);
        }
        [$allowed, $handler] = $routes[$action];
        if ($method !== $allowed && !($method === 'HEAD' && $allowed === 'GET')) {
            return Response::json(405, ['error' => "use $allowed"], ['Allow' => $allowed]);
        }
        $token = (string) $this->config->get('token');
        $given = preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m) ? $m[1] : '';
        $tokenOk = $token !== '' && hash_equals($token, $given);
        if (!in_array($action, ['health', 'login', 'logout'], true)) {
            if ($this->session->enabled()) {
                if (!$tokenOk && !$this->session->valid($cookies[Session::COOKIE] ?? null, time())) {
                    return Response::json(401, ['error' => 'please log in', 'login' => true]);
                }
            } elseif ($token !== '' && !$tokenOk) {
                return Response::json(401, ['error' => 'access token missing or wrong'], ['WWW-Authenticate' => 'Bearer']);
            }
        }
        if ($handler === 'login') {
            return $this->login($body, $secure);
        }
        if ($handler === 'logout') {
            return Response::json(200, ['ok' => true], ['Set-Cookie' => Session::cookie('', $secure, 0)]);
        }
        $serial = (string) ($query['serial'] ?? '');
        if ($serial !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $serial)) {
            return Response::json(400, ['error' => 'invalid printer serial number']);
        }
        if ($serial !== '') {
            $this->serial = $serial;
            $this->printer = $this->printer->withSerial($serial);
        }
        if ($handler === 'print') {
            return $this->print($body, $contentType);
        }
        if ($handler === 'labelSave' || $handler === 'labelDelete') {
            return $this->changeLabels($handler, $body, $contentType);
        }
        if ($handler === 'font') {
            return $this->font($query['file'] ?? '');
        }
        return $this->$handler();
    }

    private function labels(): Response
    {
        $labels = new Labels((string) $this->config->get('data_dir'));
        return Response::json(200, ['enabled' => $labels->enabled(), 'labels' => (object) $labels->all()]);
    }

    private function changeLabels(string $handler, string $body, string $contentType): Response
    {
        if (stripos($contentType, 'application/json') !== 0) {
            return Response::json(415, ['error' => 'send application/json']);
        }
        $request = json_decode($body, true);
        if (!is_array($request) || !is_string($request['name'] ?? null)) {
            return Response::json(400, ['error' => 'expected {"name": "..."}']);
        }
        $labels = new Labels((string) $this->config->get('data_dir'));
        try {
            if ($handler === 'labelSave') {
                $labels->save($request['name'], $request['label'] ?? null);
            } else {
                $labels->delete($request['name']);
            }
        } catch (InvalidArgumentException $e) {
            return Response::json(422, ['error' => $e->getMessage()]);
        }
        return Response::json(200, ['ok' => true]);
    }

    private function login(string $body, bool $secure): Response
    {
        if (!$this->session->enabled()) {
            return Response::json(400, ['error' => 'no password is set']);
        }
        $request = json_decode($body, true);
        $password = is_array($request) && is_string($request['password'] ?? null) ? $request['password'] : '';
        if (!$this->session->verify($password)) {
            usleep(self::$loginDelayMs * 1000);
            return Response::json(401, ['error' => 'wrong password', 'login' => true]);
        }
        return Response::json(200, ['ok' => true], [
            'Set-Cookie' => Session::cookie($this->session->issue(time()), $secure, Session::LIFETIME),
        ]);
    }

    private function health(): Response
    {
        return Response::json(200, ['ok' => true, 'version' => Config::VERSION]);
    }

    private function status(): Response
    {
        if ($this->remote !== null) {
            return $this->remoteStatus($this->remote);
        }
        $version = $this->printer->version();
        $info = $version === null
            ? PtouchPrint::parseInfo('', 127)
            : $this->printer->info();
        return Response::json(200, [
            'app_version' => Config::VERSION,
            'ptouch_version' => $version,
            'features' => $this->printer->features(),
            'max_copies' => (int) $this->config->get('max_copies'),
            'icon_api' => (string) $this->config->get('icon_api'),
            'password' => $this->session->enabled(),
            'shared_labels' => (new Labels((string) $this->config->get('data_dir')))->enabled(),
            'printer' => $info,
            'agent' => null,
        ]);
    }

    private function remoteStatus(Remote $remote): Response
    {
        $result = $remote->call('GET', 'status', '', $this->serial);
        $data = $result['data'] ?? [];
        if ($result['status'] === 200 && isset($data['printer']) && is_array($data['printer'])) {
            $printer = $data['printer'];
            $version = $data['ptouch_version'] ?? null;
            $features = $data['features'] ?? [];
            $maxCopies = min((int) $this->config->get('max_copies'), (int) ($data['max_copies'] ?? PHP_INT_MAX));
        } else {
            $printer = PtouchPrint::parseInfo('', 1);
            $printer['message'] = $this->remoteProblem($remote, $result);
            $version = null;
            $features = array_fill_keys(array_keys(PtouchPrint::FEATURES), false);
            $maxCopies = (int) $this->config->get('max_copies');
        }
        return Response::json(200, [
            'app_version' => Config::VERSION,
            'ptouch_version' => $version,
            'features' => $features,
            'max_copies' => $maxCopies,
            'icon_api' => (string) $this->config->get('icon_api'),
            'password' => $this->session->enabled(),
            'shared_labels' => (new Labels((string) $this->config->get('data_dir')))->enabled(),
            'printer' => $printer,
            'agent' => ['url' => $remote->url(), 'version' => $data['app_version'] ?? null, 'reachable' => $result['status'] !== 0],
        ]);
    }

    /** @param array{status: int, data: array<string, mixed>|null, error: string} $result */
    private function remoteProblem(Remote $remote, array $result): string
    {
        if ($result['status'] === 0) {
            return "The printer agent at {$remote->url()} cannot be reached: {$result['error']}.";
        }
        if ($result['status'] === 401) {
            return 'The printer agent refused the access token. Set printer_token to the token of the agent.';
        }
        $error = is_array($result['data']) && isset($result['data']['error']) ? (string) $result['data']['error'] : $result['error'];
        return "The printer agent answered HTTP {$result['status']}: {$error}.";
    }

    private function forward(string $method, string $action, string $body = ''): Response
    {
        $remote = $this->remote;
        assert($remote !== null);
        $result = $remote->call($method, $action, $body, $this->serial);
        if ($result['status'] === 0 || $result['status'] === 401 || $result['data'] === null) {
            return Response::json(502, ['ok' => false, 'error' => $this->remoteProblem($remote, $result), 'output' => '']);
        }
        return Response::json($result['status'], $result['data']);
    }

    private function print(string $body, string $contentType): Response
    {
        // A JSON content type cannot be sent cross-site without a CORS preflight,
        // which this API never answers: other web pages cannot print.
        if (stripos($contentType, 'application/json') !== 0) {
            return Response::json(415, ['error' => 'send application/json']);
        }
        $maxBytes = (int) $this->config->get('max_upload_bytes');
        if (strlen($body) > $maxBytes * 4 / 3 + 1000) {
            return Response::json(413, ['error' => 'the label is too large']);
        }
        $request = json_decode($body, true);
        if (!is_array($request) || !isset($request['png']) || !is_string($request['png'])) {
            return Response::json(400, ['error' => 'expected {"png": "<base64>"}']);
        }
        if ($this->remote !== null) {
            return $this->forward('POST', 'print', $body);
        }
        $png = base64_decode(preg_replace('/^data:image\/png;base64,/', '', $request['png']), true);
        if ($png === false || $png === '') {
            return Response::json(400, ['error' => 'png is not valid base64']);
        }
        if (strlen($png) > $maxBytes) {
            return Response::json(413, ['error' => 'the label is too large']);
        }
        $copies = (int) ($request['copies'] ?? 1);
        $maxCopies = max(1, (int) $this->config->get('max_copies'));
        if ($copies < 1 || $copies > $maxCopies) {
            return Response::json(400, ['error' => "copies must be between 1 and $maxCopies"]);
        }
        $extra = ['halfcut' => !empty($request['halfcut']), 'hires' => !empty($request['hires'])];
        foreach (['feed' => [0, 1000], 'cut_every' => [1, 99]] as $key => [$min, $max]) {
            if (!isset($request[$key]) || $request[$key] === '') {
                continue;
            }
            if (!is_int($request[$key]) || $request[$key] < $min || $request[$key] > $max) {
                return Response::json(400, ['error' => "$key must be a whole number between $min and $max"]);
            }
            $extra[$key] = $request[$key];
        }

        $info = $this->printer->info();
        if (!$info['ready']) {
            return Response::json(503, ['error' => $info['message'], 'printer' => $info]);
        }
        try {
            $size = Png::inspect($png, (int) $info['tape_px']);
        } catch (InvalidArgumentException $e) {
            return Response::json(422, ['error' => $e->getMessage()]);
        }

        $base = tempnam(sys_get_temp_dir(), 'labeler');
        $file = $base === false ? false : $base . '.png';
        if ($file === false || !rename($base, $file) || file_put_contents($file, $png) === false) {
            return Response::json(500, ['error' => 'cannot write a temporary file']);
        }
        try {
            $result = $this->printer->printImage(
                $file,
                $copies,
                !empty($request['chain']),
                !empty($request['precut']),
                $extra
            );
        } finally {
            @unlink($file);
        }
        // ptouch-print does not notice errors during printing (exit code 0 after a cutter jam
        // or an opened cover), so ask the printer once more
        if ($result['ok']) {
            $after = $this->printer->info();
            if (!$after['ready']) {
                $result['ok'] = false;
                $result['output'] = trim($result['output'] . "
" . $after['message']);
                $result['problem'] = 'After printing, the printer reports: ' . $after['message'];
            }
        }
        // in high resolution a pixel column is half as long along the tape
        $dpiAlong = max(1, (int) $info['dpi']) * ($extra['hires'] && $this->printer->supports('hires') ? 2 : 1);
        $lengthMm = round($size['width'] / $dpiAlong * 25.4, 1);
        return Response::json($result['ok'] ? 200 : 502, [
            'ok' => $result['ok'],
            'error' => $result['ok'] ? null : ($result['problem'] ?? 'ptouch-print reported a problem'),
            'output' => $result['output'],
            'notes' => $result['notes'],
            'width_px' => $size['width'],
            'height_px' => $size['height'],
            'length_mm' => $lengthMm,
            'copies' => $copies,
        ]);
    }

    /** @return list<array{name: string, file: string}> */
    public function fontList(): array
    {
        $dir = (string) $this->config->get('fonts_dir');
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $fonts = [];
        foreach ((array) scandir($dir) as $file) {
            $file = (string) $file;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($file[0] === '.' || !isset(self::FONT_TYPES[$ext])) {
                continue;
            }
            $name = trim((string) preg_replace('/[-_]+/', ' ', pathinfo($file, PATHINFO_FILENAME)));
            $fonts[] = ['name' => $name, 'file' => $file];
        }
        usort($fonts, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });
        return $fonts;
    }

    private function fonts(): Response
    {
        return Response::json(200, ['fonts' => $this->fontList()]);
    }

    private function font(string $file): Response
    {
        foreach ($this->fontList() as $font) {
            if ($font['file'] === $file) {
                $path = rtrim((string) $this->config->get('fonts_dir'), '/\\') . '/' . $file;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                return new Response(200, (string) file_get_contents($path), [
                    'Content-Type' => self::FONT_TYPES[$ext],
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }
        return Response::json(404, ['error' => 'no such font']);
    }

    private function printers(): Response
    {
        if ($this->remote !== null) {
            return $this->forward('GET', 'printers');
        }
        $printers = $this->printer->connected();
        return Response::json(200, ['supported' => $printers !== null, 'printers' => $printers ?? []]);
    }

    private function log(): Response
    {
        if ($this->remote !== null) {
            return $this->forward('GET', 'log');
        }
        return Response::json(200, ['enabled' => $this->log->enabled(), 'entries' => $this->log->entries()]);
    }
}
