<?php

/**
 * Playground web do pacote TR-069.
 * Roda no navegador via:  php -S localhost:8000 -t playground
 *
 * Carrega o pacote de verdade (autoload do Composer) e permite:
 *   0. Buscar um dispositivo na API real (findBySerial)
 *   1. Rodar a suíte de testes (PHPUnit) e ver o resultado
 *   2. Parsear um payload do GenieACS em DeviceInfo, ao vivo
 *   3. Montar query params com o QueryBuilder
 *   4. Resolver um device handler com o DeviceRegistry
 *
 * Todos os resultados são exibidos em um modal, para não poluir a tela.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

session_start();

use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\GenieACS\QueryBuilder;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\Device\DeviceRegistry;
use Plimsistemas\TR069\Device\DeviceDiscovery;
use Plimsistemas\TR069\TR069Manager;
use Plimsistemas\TR069\Vendors\ZTE\ZTEVendor;
use Plimsistemas\TR069\Vendors\FiberHome\FiberHomeVendor;
use Plimsistemas\TR069\Vendors\Intelbras\IntelbrasVendor;
use Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F\W51200FDevice;
use Plimsistemas\TR069\Exceptions\DeviceNotFoundException;
use Plimsistemas\TR069\Exceptions\DeviceNotSupportedException;
use Plimsistemas\TR069\Exceptions\GenieACSException;

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Minimal .env loader (KEY=VALUE) so the playground can build a real Client
 * without booting the full Laravel application.
 */
function load_env(string $path): array
{
    $vars = [];
    if (!is_file($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $vars[trim($k)] = trim($v, " \t\"'");
    }
    return $vars;
}

/**
 * Carrega o .env e devolve o config/tr069.php completo (com o registry de
 * devices), sem precisar bootar o Laravel. Define um env() mínimo se preciso.
 */
function tr069_config(string $root): array
{
    foreach (load_env($root . '/.env') as $k => $v) {
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
    if (!function_exists('env')) {
        function env(string $key, mixed $default = null): mixed
        {
            $v = $_ENV[$key] ?? getenv($key);
            if ($v === false || $v === null) {
                return $default;
            }
            return match (strtolower((string) $v)) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => $v,
            };
        }
    }
    $config = require $root . '/config/tr069.php';

    // Auto-descoberta dos handlers (mesma lógica do ServiceProvider): o config
    // tem precedência sobre o que for descoberto em src/Vendors.
    if ($config['auto_discover'] ?? true) {
        $discovered = DeviceDiscovery::discover(
            $root . '/src/Vendors',
            'Plimsistemas\\TR069\\Vendors'
        );
        $config['devices'] = array_replace_recursive($discovered, $config['devices'] ?? []);
    }

    return $config;
}

/**
 * Conexão GenieACS DINÂMICA: vem da sessão (informada pelo usuário na UI) e NÃO
 * do .env. O .env é usado apenas como prefill inicial dos campos, por conveniência.
 */
function tr069_connection(string $root): array
{
    if (!empty($_SESSION['tr069_conn'])) {
        return $_SESSION['tr069_conn'];
    }

    // prefill a partir do .env só na primeira carga (até o usuário salvar)
    $env = load_env($root . '/.env');
    return [
        'base_url'   => $env['TR069_BASE_URL'] ?? '',
        'username'   => $env['TR069_USERNAME'] ?? '',
        'password'   => $env['TR069_PASSWORD'] ?? '',
        'timeout'    => (int) ($env['TR069_TIMEOUT'] ?? 30),
        'verify_ssl' => filter_var($env['TR069_VERIFY_SSL'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
    ];
}

/**
 * Monta um TR069Manager com a conexão passada DINAMICAMENTE + registry descoberto.
 */
function tr069_manager(string $root): TR069Manager
{
    $devices  = tr069_config($root)['devices'] ?? [];
    $client   = new Client(tr069_connection($root));
    $manager  = new TR069Manager($client, new DeviceRegistry($devices));
    $manager->registerVendor(new ZTEVendor());
    $manager->registerVendor(new FiberHomeVendor());
    $manager->registerVendor(new IntelbrasVendor());

    return $manager;
}

/**
 * Formata segundos de uptime em "Xd Xh Xm Xs".
 */
function format_uptime(int $seconds): string
{
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $parts = [];
    if ($d) { $parts[] = "{$d}d"; }
    if ($h) { $parts[] = "{$h}h"; }
    if ($m) { $parts[] = "{$m}m"; }
    $parts[] = "{$s}s";
    return implode(' ', $parts);
}

/**
 * "há X" a partir de um timestamp ISO-8601.
 */
function time_ago(string $iso): string
{
    $diff = max(0, time() - strtotime($iso));
    if ($diff < 60)    { return "há {$diff}s"; }
    if ($diff < 3600)  { return 'há ' . intdiv($diff, 60) . 'min'; }
    if ($diff < 86400) { return 'há ' . intdiv($diff, 3600) . 'h'; }
    return 'há ' . intdiv($diff, 86400) . 'd';
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;

/* ---------------------------------------------------------------------------
 | Conexão GenieACS (dinâmica — salva na sessão, não vem do .env)
 * ------------------------------------------------------------------------- */
$connConfig = tr069_connection($root);
$connSaved  = false;
if ($action === 'set_connection') {
    $connConfig = [
        'base_url'   => trim($_POST['conn_base_url'] ?? ''),
        'username'   => trim($_POST['conn_username'] ?? ''),
        'password'   => $_POST['conn_password'] ?? '',
        'timeout'    => (int) ($_POST['conn_timeout'] ?? 30),
        'verify_ssl' => isset($_POST['conn_verify_ssl']),
    ];
    $_SESSION['tr069_conn'] = $connConfig;
    $connSaved = true;
}

/* ---------------------------------------------------------------------------
 | 0. Buscar dispositivo na API real (findBySerial)
 * ------------------------------------------------------------------------- */
$searchSerial  = $_POST['search_serial'] ?? 'FHTT953a2988';
$searchBy      = $_POST['search_by'] ?? 'serial';   // 'serial' | 'gpon'
$searchDevice  = null;   // AbstractDevice resolvido
$searchInfo    = null;   // DeviceInfo cru (mesmo sem handler)
$searchClass   = null;   // classe resolvida do handler
$searchWarning = null;
$searchError   = null;

if ($action === 'find_serial') {
    $manager = tr069_manager($root);
    $client  = $manager->client();

    try {
        $searchDevice = $searchBy === 'gpon'
            ? $manager->findByGponSn($searchSerial)
            : $manager->findBySerial($searchSerial);
        $searchInfo   = $searchDevice->info();
        $searchClass  = $searchDevice::class;
    } catch (DeviceNotFoundException $e) {
        $searchError = 'Dispositivo não encontrado: ' . $e->getMessage();
    } catch (DeviceNotSupportedException $e) {
        // Encontrado na API, mas sem handler registrado para o modelo/firmware.
        $searchWarning = 'Dispositivo encontrado, mas sem handler registrado: ' . $e->getMessage();
        $query = QueryBuilder::make()->projectDeviceId()->projectSoftwareVersion();
        $searchBy === 'gpon' ? $query->whereGponSn($searchSerial) : $query->whereSerial($searchSerial);
        $results = $client->searchDevices($query);
        if (!empty($results)) {
            $searchInfo = DeviceInfo::fromResponse($results[0]);
        }
    } catch (GenieACSException $e) {
        $searchError = 'Falha na conexão com o GenieACS: ' . $e->getMessage();
    } catch (\Throwable $e) {
        $searchError = get_class($e) . ': ' . $e->getMessage();
    }
}

/* ---------------------------------------------------------------------------
 | 0b. Uptime atualizado (getParameterValues + leitura)
 * ------------------------------------------------------------------------- */
$upSerial   = $_POST['up_serial'] ?? 'FHTT953a2988';
$upPath     = $_POST['up_path'] ?? 'InternetGatewayDevice.DeviceInfo.UpTime';
$upValue    = null;   // valor cru (segundos)
$upFormatted= null;   // "Xh Xm Xs"
$upTimestamp= null;   // _timestamp do parâmetro
$upAge      = null;   // "há X"
$upTaskId   = null;   // id da task de refresh enfileirada
$upError    = null;

if ($action === 'get_uptime') {
    try {
        $client = new Client(tr069_connection($root));

        // 1) descobre o id pelo serial
        $found = $client->searchDevices(
            QueryBuilder::make()->whereSerial($upSerial)->projectDeviceId()
        );
        if (empty($found)) {
            throw new \RuntimeException("Nenhum device com serial '{$upSerial}'.");
        }
        $deviceId = $found[0]->getId();

        // 2) dispara o refresh (connection request -> valor atual no próximo inform)
        $task     = $client->getParameterValues($deviceId, [$upPath]);
        $upTaskId = $task->getId();

        // 3) lê o valor atualmente conhecido + timestamp
        $device = $client->getDevice($deviceId, $upPath);
        $node   = $device->getParameter($upPath); // pode ser escalar ou node cru

        // getParameter devolve o _value; pegamos o node cru para o timestamp
        $parts = explode('.', $upPath);
        $raw   = $device->raw();
        foreach ($parts as $p) {
            $raw = $raw[$p] ?? null;
            if ($raw === null) break;
        }

        if ($node === null) {
            $upError = "Parâmetro '{$upPath}' ainda não conhecido para este device.";
        } else {
            $upValue     = (int) $node;
            $upFormatted = format_uptime($upValue);
            if (is_array($raw) && isset($raw['_timestamp'])) {
                $upTimestamp = $raw['_timestamp'];
                $upAge       = time_ago($upTimestamp);
            }
        }
    } catch (\Throwable $e) {
        $upError = get_class($e) . ': ' . $e->getMessage();
    }
}

/* ---------------------------------------------------------------------------
 | 0c. Fetch genérico de dados atualizados (agnóstico de fabricante)
 * ------------------------------------------------------------------------- */
$fxSerial  = $_POST['fx_serial'] ?? 'ZTEGdb18e99d';
$fxBy      = $_POST['fx_by'] ?? 'gpon';   // 'gpon' | 'serial'
$fxReading = null;   // DeviceReading
$fxHandler = null;
$fxError   = null;

if ($action === 'fetch_data') {
    try {
        $manager = tr069_manager($root);

        $device    = $fxBy === 'gpon'
            ? $manager->findByGponSn($fxSerial)
            : $manager->findBySerial($fxSerial);
        $fxHandler = $device::class;

        $fxReading = $device->fetch()
            ->uptime()
            ->txPower()
            ->rxPower()
            ->swVersion()
            ->hwVersion()
            ->gponSn()
            ->execute();
    } catch (\Throwable $e) {
        $fxError = get_class($e) . ': ' . $e->getMessage();
    }
}

/* ---------------------------------------------------------------------------
 | 0d. Ler UMA chave canônica arbitrária (fetch()->add($key))
 * ------------------------------------------------------------------------- */
$rpId      = $_POST['rp_id'] ?? 'ZTEGdb18e99d';
$rpBy      = $_POST['rp_by'] ?? 'gpon';   // 'gpon' | 'serial'
$rpKey     = $_POST['rp_key'] ?? 'easy_mesh.version';
$rpValue   = null;
$rpPath    = null;
$rpHandler = null;
$rpError   = null;

if ($action === 'read_param') {
    try {
        $manager = tr069_manager($root);

        $device    = $rpBy === 'gpon'
            ? $manager->findByGponSn($rpId)
            : $manager->findBySerial($rpId);
        $rpHandler = $device::class;
        $rpPath    = $device->pathFor($rpKey); // path TR-069 resolvido (ou null)

        // Lê a chave via o fetch genérico (add() aceita qualquer chave mapeada).
        $reading = $device->fetch()->add($rpKey)->execute();
        $rpValue = $reading->get($rpKey);
    } catch (\Throwable $e) {
        $rpError = get_class($e) . ': ' . $e->getMessage();
    }
}

/* ---------------------------------------------------------------------------
 | 1. Test suite runner
 * ------------------------------------------------------------------------- */
$testOutput = null;
$testOk = null;
if ($action === 'run_tests') {
    chdir($root);
    $cmd = 'php ' . escapeshellarg($root . '/vendor/bin/phpunit')
         . ' --configuration ' . escapeshellarg($root . '/phpunit.xml')
         . ' --testdox --colors=never 2>&1';
    $testOutput = shell_exec($cmd) ?? '(sem saída)';
    $testOk = str_contains($testOutput, 'OK');
}

/* ---------------------------------------------------------------------------
 | 2. Device parser (GenieACS -> DeviceInfo)
 * ------------------------------------------------------------------------- */
$sampleJson = <<<JSON
{
  "_id": "202BC1-W5-1200F-ABC123",
  "_deviceId": {
    "_Manufacturer": "Intelbras",
    "_OUI": "202BC1",
    "_ProductClass": "W5-1200F",
    "_SerialNumber": "ABC123"
  },
  "InternetGatewayDevice": {
    "DeviceInfo": {
      "SoftwareVersion": { "_value": "1.0.0" }
    }
  }
}
JSON;

$deviceJson  = $_POST['device_json'] ?? $sampleJson;
$deviceInfo  = null;
$deviceError = null;
if ($action === 'parse_device') {
    $data = json_decode($deviceJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $deviceError = 'JSON inválido: ' . json_last_error_msg();
    } else {
        $deviceInfo = DeviceInfo::fromResponse(new DeviceResponse($data));
    }
}

/* ---------------------------------------------------------------------------
 | 3. Query builder
 * ------------------------------------------------------------------------- */
$qSerial       = $_POST['q_serial'] ?? 'ABC123';
$qManufacturer = $_POST['q_manufacturer'] ?? '';
$qProductClass = $_POST['q_product_class'] ?? '';
$qProjectDevId = isset($_POST['q_project_devid']) || $action !== 'build_query';
$qProjectSw    = isset($_POST['q_project_sw']);
$queryParams   = null;
if ($action === 'build_query') {
    $qb = QueryBuilder::make();
    if ($qSerial !== '')       { $qb->whereSerial($qSerial); }
    if ($qManufacturer !== '') { $qb->whereManufacturer($qManufacturer); }
    if ($qProductClass !== '') { $qb->whereProductClass($qProductClass); }
    if ($qProjectDevId)        { $qb->projectDeviceId(); }
    if ($qProjectSw)           { $qb->projectSoftwareVersion(); }
    $queryParams = $qb->toQueryParams();
}

/* ---------------------------------------------------------------------------
 | 4. Device registry resolver
 * ------------------------------------------------------------------------- */
$rVendor   = $_POST['r_vendor'] ?? 'intelbras';
$rModel    = $_POST['r_model'] ?? 'W5-1200F';
$rFirmware = $_POST['r_firmware'] ?? '1.0.0';
$resolved  = null;
$resolveError = null;
if ($action === 'resolve_device') {
    $registry = new DeviceRegistry();
    $registry->register('intelbras', 'W5-1200F', '*', W51200FDevice::class);
    try {
        $resolved = $registry->resolve($rVendor, $rModel, $rFirmware);
    } catch (\Throwable $e) {
        $resolveError = $e->getMessage();
    }
}

/* ---------------------------------------------------------------------------
 | Modal: título por ação. Renderizado só quando há um resultado.
 * ------------------------------------------------------------------------- */
$modalTitles = [
    'set_connection' => '🔌 Conexão GenieACS',
    'find_serial'    => '📡 Resultado da busca',
    'fetch_data'     => '📊 Dados atualizados do dispositivo',
    'read_param'     => '🧩 Leitura de parâmetro por chave',
    'get_uptime'     => '⏱️ Uptime do dispositivo',
    'run_tests'      => '✅ Resultado dos testes',
    'parse_device'   => '🔍 DeviceInfo parseado',
    'build_query'    => '🧩 Query params gerados',
    'resolve_device' => '🗂️ Resolução do registry',
];
$showModal = isset($modalTitles[$action]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TR-069 · Playground</title>
<style>
  :root { --bg:#0d1117; --panel:#161b22; --border:#30363d; --txt:#e6edf3;
          --muted:#8b949e; --accent:#2f81f7; --green:#3fb950; --red:#f85149; --warn:#d29922; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;
         background:var(--bg); color:var(--txt); }
  header { padding:24px 32px; border-bottom:1px solid var(--border);
           background:linear-gradient(90deg,#161b22,#0d1117); }
  header h1 { margin:0; font-size:20px; }
  header p { margin:4px 0 0; color:var(--muted); font-size:13px; }
  .wrap { max-width:980px; margin:0 auto; padding:24px 32px;
          display:grid; grid-template-columns:repeat(2, 1fr);
          gap:18px; align-items:start; }
  .card { background:var(--panel); border:1px solid var(--border);
          border-radius:10px; padding:16px 18px; }
  .card.full { grid-column:1 / -1; }
  @media (max-width:720px) { .wrap { grid-template-columns:1fr; } }
  .card h2 { margin:0 0 10px; font-size:15px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .card h2 .tag { font-size:11px; background:#21262d; color:var(--muted);
                  padding:2px 8px; border-radius:20px; font-weight:400; }
  label { display:block; font-size:13px; color:var(--muted); margin:10px 0 4px; }
  input[type=text], textarea {
    width:100%; background:#0d1117; border:1px solid var(--border);
    color:var(--txt); border-radius:7px; padding:9px 11px; font:14px monospace; }
  textarea { min-height:130px; resize:vertical; }
  .row { display:flex; gap:14px; flex-wrap:wrap; }
  .row > div { flex:1; min-width:160px; }
  .inline { display:flex; gap:10px; align-items:stretch; }
  .inline input { flex:1; }
  .inline button { margin-top:0; white-space:nowrap; }
  .checks { display:flex; gap:18px; margin:12px 0 4px; font-size:13px; color:var(--txt); }
  .checks label { display:inline-flex; align-items:center; gap:6px; margin:0; color:var(--txt); }
  button { margin-top:14px; background:var(--accent); color:#fff; border:0;
           padding:9px 18px; border-radius:7px; font-size:14px; cursor:pointer; font-weight:600; }
  button:hover { filter:brightness(1.1); }
  pre { background:#0d1117; border:1px solid var(--border); border-radius:7px;
        padding:14px; overflow:auto; font-size:13px; margin:14px 0 0; }
  table { width:100%; border-collapse:collapse; margin-top:14px; font-size:14px; }
  td, th { text-align:left; padding:7px 10px; border-bottom:1px solid var(--border); }
  th { color:var(--muted); font-weight:500; width:170px; }
  .badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:13px; font-weight:600; }
  .badge.ok  { background:rgba(63,185,80,.15);  color:var(--green); }
  .badge.err { background:rgba(248,81,73,.15);  color:var(--red); }
  code.mono { color:#79c0ff; }
  .null { color:var(--muted); font-style:italic; }

  /* ----- Modal ----- */
  .modal-backdrop { position:fixed; inset:0; background:rgba(1,4,9,.7);
    display:flex; align-items:flex-start; justify-content:center; z-index:50;
    padding:48px 24px; overflow:auto; backdrop-filter:blur(2px);
    animation:fade .15s ease; }
  .modal { background:var(--panel); border:1px solid var(--border);
    border-radius:12px; max-width:780px; width:100%; padding:24px 26px 28px;
    position:relative; box-shadow:0 24px 70px rgba(0,0,0,.6);
    animation:pop .15s ease; }
  .modal h3 { margin:0 30px 18px 0; font-size:17px; }
  .modal-close { position:absolute; top:12px; right:14px; margin:0; padding:2px 8px;
    background:transparent; color:var(--muted); font-size:24px; line-height:1;
    border-radius:6px; }
  .modal-close:hover { color:var(--txt); background:#21262d; }
  .modal pre, .modal table { margin-top:12px; }
  @keyframes fade { from { opacity:0; } to { opacity:1; } }
  @keyframes pop  { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:none; } }
</style>
</head>
<body>
<header>
  <h1>🛰️ TR-069 · Playground</h1>
  <p>Rodando o pacote <code class="mono">plimsistemas/tr069</code> ao vivo no navegador</p>
</header>
<div class="wrap">

  <!-- CONEXÃO DINÂMICA -->
  <div class="card full">
    <h2>🔌 Conexão GenieACS <span class="tag">dinâmica · passada em runtime (não do .env)</span></h2>
    <p style="color:var(--muted);font-size:13px;margin:0 0 4px">
      Usada por todos os cards abaixo. <code class="mono">TR069::connection([...])</code> —
      o <code class="mono">.env</code> só prefilla os campos na 1ª carga.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="set_connection">
      <div class="row">
        <div style="flex:2"><label>Base URL</label><input type="text" name="conn_base_url" value="<?= h($connConfig['base_url'] ?? '') ?>" placeholder="https://acs.provedor.com/api"></div>
        <div><label>Usuário</label><input type="text" name="conn_username" value="<?= h($connConfig['username'] ?? '') ?>"></div>
        <div><label>Senha</label><input type="text" name="conn_password" value="<?= h($connConfig['password'] ?? '') ?>"></div>
      </div>
      <div class="checks">
        <label><input type="checkbox" name="conn_verify_ssl" <?= !empty($connConfig['verify_ssl']) ? 'checked' : '' ?>> verify_ssl</label>
        <span style="color:var(--muted)">timeout: <input type="text" name="conn_timeout" value="<?= h($connConfig['timeout'] ?? 30) ?>" style="width:60px;display:inline-block"> s</span>
      </div>
      <button type="submit">🔌 Salvar conexão</button>
    </form>
  </div>

  <!-- 0. BUSCAR DISPOSITIVO (API REAL) -->
  <div class="card">
    <h2>📡 Buscar dispositivo <span class="tag">findBySerial() · API real</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="find_serial">
      <label>Buscar por</label>
      <div class="checks">
        <label><input type="radio" name="search_by" value="serial" <?= $searchBy === 'serial' ? 'checked' : '' ?>> Serial Number</label>
        <label><input type="radio" name="search_by" value="gpon" <?= $searchBy === 'gpon' ? 'checked' : '' ?>> GPON SN</label>
      </div>
      <div class="inline">
        <input type="text" name="search_serial" value="<?= h($searchSerial) ?>">
        <button type="submit">🔎 Buscar na API</button>
      </div>
    </form>
  </div>

  <!-- 0a. FETCH GENÉRICO -->
  <div class="card full">
    <h2>📊 Dados atualizados <span class="tag">fetch() genérico · qualquer marca</span></h2>
    <p style="color:var(--muted);font-size:13px;margin:0 0 4px">
      <code class="mono">$device-&gt;fetch()-&gt;uptime()-&gt;txPower()-&gt;rxPower()-&gt;swVersion()-&gt;hwVersion()-&gt;execute()</code>
    </p>
    <form method="post">
      <input type="hidden" name="action" value="fetch_data">
      <label>Buscar por</label>
      <div class="checks">
        <label><input type="radio" name="fx_by" value="gpon" <?= $fxBy === 'gpon' ? 'checked' : '' ?>> GPON SN</label>
        <label><input type="radio" name="fx_by" value="serial" <?= $fxBy === 'serial' ? 'checked' : '' ?>> Serial Number</label>
      </div>
      <div class="inline">
        <input type="text" name="fx_serial" value="<?= h($fxSerial) ?>">
        <button type="submit">📊 Obter dados</button>
      </div>
    </form>
  </div>

  <!-- 0d. LER PARÂMETRO POR CHAVE -->
  <div class="card full">
    <h2>🧩 Ler parâmetro <span class="tag">fetch()-&gt;add($chave) · qualquer chave mapeada</span></h2>
    <p style="color:var(--muted);font-size:13px;margin:0 0 4px">
      <code class="mono">$device-&gt;fetch()-&gt;add('easy_mesh.version')-&gt;execute()-&gt;get('easy_mesh.version')</code>
    </p>
    <form method="post">
      <input type="hidden" name="action" value="read_param">
      <div class="row">
        <div>
          <label>Buscar por</label>
          <div class="checks">
            <label><input type="radio" name="rp_by" value="gpon" <?= $rpBy === 'gpon' ? 'checked' : '' ?>> GPON SN</label>
            <label><input type="radio" name="rp_by" value="serial" <?= $rpBy === 'serial' ? 'checked' : '' ?>> Serial</label>
          </div>
        </div>
        <div><label>Identificador</label><input type="text" name="rp_id" value="<?= h($rpId) ?>"></div>
        <div><label>Chave canônica</label><input type="text" name="rp_key" value="<?= h($rpKey) ?>"></div>
      </div>
      <button type="submit">🧩 Ler chave</button>
    </form>
  </div>

  <!-- 0b. UPTIME -->
  <div class="card">
    <h2>⏱️ Uptime <span class="tag">getParameterValues · atualizado</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="get_uptime">
      <label>Serial Number</label>
      <input type="text" name="up_serial" value="<?= h($upSerial) ?>">
      <label>Parâmetro TR-069</label>
      <div class="inline">
        <input type="text" name="up_path" value="<?= h($upPath) ?>">
        <button type="submit">⏱️ Obter uptime</button>
      </div>
    </form>
  </div>

  <!-- 1. TESTES -->
  <div class="card">
    <h2>✅ Suíte de testes <span class="tag">PHPUnit</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="run_tests">
      <button type="submit">▶ Rodar testes</button>
    </form>
  </div>

  <!-- 2. DEVICE PARSER -->
  <div class="card full">
    <h2>🔍 Parser de device <span class="tag">GenieACS → DeviceInfo</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="parse_device">
      <label>Payload do GenieACS (JSON)</label>
      <textarea name="device_json"><?= h($deviceJson) ?></textarea>
      <button type="submit">Parsear</button>
    </form>
  </div>

  <!-- 3. QUERY BUILDER -->
  <div class="card">
    <h2>🧩 QueryBuilder <span class="tag">→ params da API</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="build_query">
      <div class="row">
        <div><label>Serial</label><input type="text" name="q_serial" value="<?= h($qSerial) ?>"></div>
        <div><label>Manufacturer</label><input type="text" name="q_manufacturer" value="<?= h($qManufacturer) ?>"></div>
        <div><label>Product Class</label><input type="text" name="q_product_class" value="<?= h($qProductClass) ?>"></div>
      </div>
      <div class="checks">
        <label><input type="checkbox" name="q_project_devid" <?= $qProjectDevId ? 'checked' : '' ?>> projectDeviceId()</label>
        <label><input type="checkbox" name="q_project_sw" <?= $qProjectSw ? 'checked' : '' ?>> projectSoftwareVersion()</label>
      </div>
      <button type="submit">Montar query</button>
    </form>
  </div>

  <!-- 4. REGISTRY -->
  <div class="card">
    <h2>🗂️ DeviceRegistry <span class="tag">resolve()</span></h2>
    <p style="color:var(--muted);font-size:13px;margin:0 0 6px">
      Registrado: <code class="mono">intelbras / W5-1200F / *</code> → W51200FDevice
    </p>
    <form method="post">
      <input type="hidden" name="action" value="resolve_device">
      <div class="row">
        <div><label>Vendor</label><input type="text" name="r_vendor" value="<?= h($rVendor) ?>"></div>
        <div><label>Model</label><input type="text" name="r_model" value="<?= h($rModel) ?>"></div>
        <div><label>Firmware</label><input type="text" name="r_firmware" value="<?= h($rFirmware) ?>"></div>
      </div>
      <button type="submit">Resolver</button>
    </form>
  </div>

</div>

<?php if ($showModal): ?>
<div class="modal-backdrop" id="modal">
  <div class="modal">
    <button type="button" class="modal-close" data-close aria-label="Fechar">&times;</button>
    <h3><?= h($modalTitles[$action]) ?></h3>

    <?php if ($action === 'set_connection'): ?>
      <p><span class="badge ok">conexão salva</span> — usada por todos os cards (via sessão)</p>
      <table>
        <tr><th>base_url</th><td><code class="mono"><?= h($connConfig['base_url']) ?: '<span class="null">(vazio)</span>' ?></code></td></tr>
        <tr><th>username</th><td><?= h($connConfig['username']) ?: '<span class="null">—</span>' ?></td></tr>
        <tr><th>password</th><td><?= $connConfig['password'] !== '' ? '••••••' : '<span class="null">—</span>' ?></td></tr>
        <tr><th>timeout</th><td><?= h($connConfig['timeout']) ?>s</td></tr>
        <tr><th>verify_ssl</th><td><?= !empty($connConfig['verify_ssl']) ? 'true' : 'false' ?></td></tr>
      </table>

    <?php elseif ($action === 'find_serial'): ?>
      <?php if ($searchError): ?>
        <p><span class="badge err">erro</span></p>
        <pre style="color:var(--red)"><?= h($searchError) ?></pre>
      <?php elseif ($searchInfo): ?>
        <p>
          <span class="badge ok">encontrado</span>
          <?php if ($searchClass): ?>
            &nbsp;handler: <code class="mono"><?= h($searchClass) ?></code>
          <?php endif; ?>
        </p>
        <?php if ($searchWarning): ?>
          <pre style="color:var(--warn)"><?= h($searchWarning) ?></pre>
        <?php endif; ?>
        <table>
          <tr><th>id</th><td><code class="mono"><?= h($searchInfo->id) ?></code></td></tr>
          <tr><th>manufacturer</th><td><?= h($searchInfo->manufacturer) ?></td></tr>
          <tr><th>oui</th><td><?= h($searchInfo->oui) ?></td></tr>
          <tr><th>productClass</th><td><?= h($searchInfo->productClass) ?></td></tr>
          <tr><th>serialNumber</th><td><?= h($searchInfo->serialNumber) ?></td></tr>
          <tr><th>softwareVersion</th><td>
            <?= $searchInfo->softwareVersion === null
                  ? '<span class="null">null</span>'
                  : h($searchInfo->softwareVersion) ?>
          </td></tr>
        </table>
      <?php endif; ?>

    <?php elseif ($action === 'fetch_data'): ?>
      <?php if ($fxError): ?>
        <p><span class="badge err">erro</span></p>
        <pre style="color:var(--red)"><?= h($fxError) ?></pre>
      <?php elseif ($fxReading): ?>
        <p><span class="badge ok">atualizado</span>
           &nbsp;handler: <code class="mono"><?= h($fxHandler) ?></code></p>
        <table>
          <tr><th>GPON SN</th><td>
            <?= $fxReading->getGponSn() === null ? '<span class="null">—</span>' : '<code class="mono">' . h($fxReading->getGponSn()) . '</code>' ?>
          </td></tr>
          <tr><th>uptime</th><td>
            <?= $fxReading->getUptime() === null ? '<span class="null">—</span>'
                  : h(format_uptime($fxReading->getUptime())) . ' <span style="color:var(--muted)">(' . h($fxReading->getUptime()) . 's)</span>' ?>
          </td></tr>
          <tr><th>TX power</th><td>
            <?= $fxReading->getTxPower() === null ? '<span class="null">—</span>' : h($fxReading->getTxPower()) . ' dBm' ?>
          </td></tr>
          <tr><th>RX power</th><td>
            <?= $fxReading->getRxPower() === null ? '<span class="null">—</span>' : h($fxReading->getRxPower()) . ' dBm' ?>
          </td></tr>
          <tr><th>software</th><td><?= $fxReading->getSwVersion() === null ? '<span class="null">—</span>' : h($fxReading->getSwVersion()) ?></td></tr>
          <tr><th>hardware</th><td><?= $fxReading->getHwVersion() === null ? '<span class="null">—</span>' : h($fxReading->getHwVersion()) ?></td></tr>
        </table>
        <pre style="color:var(--muted)">Valores lidos via 1 task getParameterValues (connection_request,
sem fila). Paths resolvidos pelo handler de cada fabricante.</pre>
      <?php endif; ?>

    <?php elseif ($action === 'read_param'): ?>
      <?php if ($rpError): ?>
        <p><span class="badge err">erro</span></p>
        <pre style="color:var(--red)"><?= h($rpError) ?></pre>
      <?php else: ?>
        <p><span class="badge ok">lido</span>
           &nbsp;handler: <code class="mono"><?= h($rpHandler) ?></code></p>
        <table>
          <tr><th>chave</th><td><code class="mono"><?= h($rpKey) ?></code></td></tr>
          <tr><th>path TR-069</th><td>
            <?= $rpPath === null
                  ? '<span class="null">não mapeada neste handler</span>'
                  : '<code class="mono">' . h($rpPath) . '</code>' ?>
          </td></tr>
          <tr><th>valor</th><td>
            <?= $rpValue === null
                  ? '<span class="null">null</span>'
                  : '<strong>' . h($rpValue) . '</strong>' ?>
          </td></tr>
        </table>
      <?php endif; ?>

    <?php elseif ($action === 'get_uptime'): ?>
      <?php if ($upError): ?>
        <p><span class="badge err">erro</span></p>
        <pre style="color:var(--red)"><?= h($upError) ?></pre>
      <?php else: ?>
        <p style="font-size:30px;font-weight:700;margin:6px 0 2px">
          <?= h($upFormatted) ?>
        </p>
        <p style="color:var(--muted);margin:0 0 14px">
          <?= h(number_format($upValue, 0, ',', '.')) ?> segundos
          <?php if ($upAge): ?> · informado <?= h($upAge) ?><?php endif; ?>
        </p>
        <table>
          <tr><th>parâmetro</th><td><code class="mono"><?= h($upPath) ?></code></td></tr>
          <tr><th>valor</th><td><?= h($upValue) ?></td></tr>
          <tr><th>timestamp</th><td><?= $upTimestamp ? h($upTimestamp) : '<span class="null">—</span>' ?></td></tr>
          <tr><th>refresh task</th><td>
            <?= $upTaskId ? '<code class="mono">' . h($upTaskId) . '</code>' : '<span class="null">—</span>' ?>
          </td></tr>
        </table>
        <pre style="color:var(--warn)">A task de refresh foi enfileirada (connection request). Se o
dispositivo estiver acessível, o valor é atualizado no próximo
inform; caso contrário, exibe-se o último valor conhecido acima.</pre>
      <?php endif; ?>

    <?php elseif ($action === 'run_tests'): ?>
      <p>
        Resultado:
        <span class="badge <?= $testOk ? 'ok' : 'err' ?>"><?= $testOk ? 'PASSOU' : 'FALHOU' ?></span>
      </p>
      <pre><?= h($testOutput) ?></pre>

    <?php elseif ($action === 'parse_device'): ?>
      <?php if ($deviceError): ?>
        <p><span class="badge err">erro</span></p>
        <pre style="color:var(--red)"><?= h($deviceError) ?></pre>
      <?php elseif ($deviceInfo): ?>
        <table>
          <tr><th>id</th><td><?= h($deviceInfo->id) ?: '<span class="null">(vazio)</span>' ?></td></tr>
          <tr><th>manufacturer</th><td><?= h($deviceInfo->manufacturer) ?></td></tr>
          <tr><th>oui</th><td><?= h($deviceInfo->oui) ?></td></tr>
          <tr><th>productClass</th><td><?= h($deviceInfo->productClass) ?></td></tr>
          <tr><th>serialNumber</th><td><?= h($deviceInfo->serialNumber) ?></td></tr>
          <tr><th>softwareVersion</th><td>
            <?= $deviceInfo->softwareVersion === null
                  ? '<span class="null">null</span>'
                  : h($deviceInfo->softwareVersion) ?>
          </td></tr>
        </table>
      <?php endif; ?>

    <?php elseif ($action === 'build_query'): ?>
      <pre><?= h(json_encode($queryParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>

    <?php elseif ($action === 'resolve_device'): ?>
      <?php if ($resolveError): ?>
        <p><span class="badge err">não suportado</span></p>
        <pre style="color:var(--red)"><?= h($resolveError) ?></pre>
      <?php elseif ($resolved): ?>
        <p><span class="badge ok">resolvido</span></p>
        <pre><?= h($resolved) ?></pre>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<script>
  (function () {
    var modal = document.getElementById('modal');
    if (!modal) return;
    function close() { modal.remove(); }
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    modal.querySelector('[data-close]').addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  })();
</script>
</body>
</html>
