# TR-069 for Laravel

Pacote Laravel para gerenciar equipamentos (ONTs, roteadores) via **TR-069/CWMP**
usando a API do **GenieACS** — de forma **agnóstica de fabricante, modelo e firmware**.

```php
$device = TR069::findByGponSn('ZTEGDB18E99D');

$reading = $device->fetch()
    ->uptime()->txPower()->rxPower()->swVersion()->hwVersion()
    ->execute();

$reading->getRxPower();  // -18.93   (o path TR-069 certo é resolvido por marca/modelo/firmware)
```

---

## Índice

- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Conceitos](#conceitos)
- [Uso](#uso)
  - [Encontrar um dispositivo](#encontrar-um-dispositivo)
  - [Ler dados atualizados (genérico)](#ler-dados-atualizados-genérico)
  - [Ler/escrever parâmetros](#lerescrever-parâmetros)
  - [Ações no dispositivo](#ações-no-dispositivo)
- [Equipamentos suportados](#equipamentos-suportados)
- [Adicionar um novo equipamento](#adicionar-um-novo-equipamento)
- [Resolução de parâmetros em camadas](#resolução-de-parâmetros-em-camadas)
- [Playground](#playground)
- [Testes](#testes)

---

## Requisitos

- PHP **8.1+**
- Laravel **10 ou 11** (`illuminate/support`)
- Uma instância do **GenieACS** acessível via NBI (REST API)

## Instalação

```bash
composer require plimsistemas/tr069
```

O service provider e a facade `TR069` são registrados automaticamente (package discovery).

Publique o arquivo de configuração (opcional):

```bash
php artisan vendor:publish --tag=tr069-config
```

## Configuração

Defina no `.env`:

```dotenv
TR069_BASE_URL=https://tr069.seuprovedor.com.br/api
TR069_USERNAME=admin
TR069_PASSWORD=secret
TR069_TIMEOUT=30
TR069_VERIFY_SSL=false
TR069_AUTO_DISCOVER=true
```

O `config/tr069.php` expõe:

| Chave | Env | Descrição |
|---|---|---|
| `base_url` | `TR069_BASE_URL` | URL base da API GenieACS. |
| `username` / `password` | `TR069_USERNAME` / `TR069_PASSWORD` | Basic auth (opcional). |
| `timeout` | `TR069_TIMEOUT` | Timeout HTTP (segundos). |
| `verify_ssl` | `TR069_VERIFY_SSL` | Verificar certificado SSL. |
| `auto_discover` | `TR069_AUTO_DISCOVER` | Auto-registrar handlers de `src/Vendors`. |
| `devices` | — | Mapa manual `vendor → model → firmware → class` (override). |

---

## Conceitos

- **Handler de dispositivo** — uma classe (`extends AbstractDevice`) que mapeia chaves
  amigáveis (ex.: `wifi.2g.ssid`, `gpon_sn`) para os paths TR-069 daquele equipamento.
- **Chaves canônicas** — chaves padronizadas e agnósticas (`uptime`, `optical.tx_power`,
  `optical.rx_power`, `sw_version`, `hw_version`, `gpon_sn`) que funcionam em qualquer marca.
- **Resolução em camadas** — o mapa de parâmetros é herdado por
  **Marca → Modelo → Modelo/Firmware**, com a camada mais específica vencendo.
- **GPON SN** — número de série GPON (virtual param `VirtualParameters.GponSN` no GenieACS),
  geralmente **diferente** do serial TR-069.

---

## Uso

### Conexão dinâmica (multi-tenant)

A conexão GenieACS pode ser passada **em runtime**, sem depender do `.env` — ideal quando
cada provedor/tenant tem seu próprio ACS:

```php
$acs = TR069::connection([
    'base_url' => $tenant->acs_url,
    'username' => $tenant->acs_user,
    'password' => $tenant->acs_pass,
    // 'timeout' => 30, 'verify_ssl' => false,  // opcionais
]);

$device = $acs->findByGponSn('ZTEGDB18E99D');
```

`connection()` devolve um manager para aquela conexão, reaproveitando os handlers/vendors.
A config do `.env`/`config/tr069.php` é apenas a conexão **padrão** (opcional).

### Encontrar um dispositivo

```php
use Plimsistemas\TR069\Facades\TR069;

$device = TR069::findBySerial('FHTT953A2988');   // por serial TR-069
$device = TR069::findByGponSn('ZTEGDB18E99D');   // por GPON SN (virtual param)
$device = TR069::find('000AC2-HG6143D-FHTT953A2988'); // por ID GenieACS
```

Lança `DeviceNotFoundException` se não encontrar e `DeviceNotSupportedException` se não
houver handler registrado para o modelo/firmware.

```php
$device->info()->manufacturer;     // 'FiberHome'
$device->info()->productClass;     // 'HG6143D'
$device->info()->softwareVersion;  // 'RP2815'
```

### Ler dados atualizados (genérico)

Dispara **uma** task `getParameterValues` (connection request, sem enfileirar), lê os
valores frescos e devolve um resultado tipado. Funciona igual para qualquer fabricante:

```php
$reading = $device->fetch()
    ->uptime()
    ->txPower()
    ->rxPower()
    ->swVersion()
    ->hwVersion()
    ->gponSn()
    ->timeout(25000)   // opcional, ms
    ->execute();

$reading->getUptime();     // ?int    (segundos)
$reading->getTxPower();    // ?float  (dBm)
$reading->getRxPower();    // ?float  (dBm)
$reading->getSwVersion();  // ?string
$reading->getHwVersion();  // ?string
$reading->getGponSn();     // ?string
$reading->all();           // array bruto chave => valor
```

> Se o dispositivo estiver inacessível (NAT/CGNAT) e não responder a tempo, a task é
> descartada e um `TaskException` é lançado.

### Ler/escrever parâmetros

Por chave amigável (mapeada no handler):

```php
$ssid = $device->get('wifi.2g.ssid');
$device->set('wifi.2g.ssid', 'MinhaRede');

$device->getMany(['wifi.2g.ssid', 'wifi.5g.ssid']);
$device->setMany([
    'wifi.2g.ssid'     => 'MinhaRede',
    'wifi.2g.password' => 'senha123',
]);

// Métodos de conveniência (variam por handler):
$device->setWifi2gCredentials('MinhaRede', 'senha123');
```

Por path TR-069 cru:

```php
$device->getPath('InternetGatewayDevice.DeviceInfo.UpTime');
$device->setPath('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'X');
```

### Status online

```php
// "Está online?" — força um connection request (tempo real)
TR069::isOnline('000AC2-HG6143D-FHTT953A2988');  // bool, por device id
$device->isOnline();                              // bool, pelo handler

// Diferente de: status da conexão WAN/internet (pode ser cacheado)
$device->isWanConnected();                         // bool
```

`isOnline()` dispara uma task leve via connection request — `true` se o dispositivo
respondeu ao ACS, `false` se não foi alcançado no timeout.

### Ações no dispositivo

```php
$device->reboot();
$device->factoryReset();
```

### Listar / consultar

```php
use Plimsistemas\TR069\GenieACS\QueryBuilder;

$devices = TR069::devices(
    QueryBuilder::make()->whereManufacturer('ZTE')->projectDeviceId()
); // Collection<DeviceInfo>
```

---

## Equipamentos suportados

| Fabricante | Modelo | Handler |
|---|---|---|
| ZTE | F670L | `Vendors\ZTE\Devices\F670L\F670LDevice` |
| ZTE | F6201B | `Vendors\ZTE\Devices\F6201B\F6201BDevice` |
| FiberHome | HG6143D | `Vendors\FiberHome\Devices\HG6143D\HG6143DDevice` |
| Intelbras | W5-1200F | `Vendors\Intelbras\Devices\W51200F\W51200FDevice` |
| — | qualquer | `Vendors\Generic\GenericDevice` (fallback, só path cru) |

---

## Adicionar um novo equipamento

1. Crie o handler estendendo a **base da marca** (ou `AbstractDevice`):

```php
namespace Plimsistemas\TR069\Vendors\ZTE\Devices\XPTO;

use Plimsistemas\TR069\Vendors\ZTE\ZTEDevice;

class XPTODevice extends ZTEDevice
{
    public function model(): string { return 'XPTO'; }
    public function firmwareVersion(): string { return $this->deviceInfo->softwareVersion ?? '*'; }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // apenas o que é específico deste modelo
            'wifi.2g.ssid' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        ]);
    }
}
```

2. Com `auto_discover` ligado (default), **nada mais é preciso** — o handler é registrado
   sozinho. Para forçar/override, adicione em `config/tr069.php`:

```php
'devices' => [
    'zte' => [
        'XPTO' => [
            '*' => \Plimsistemas\TR069\Vendors\ZTE\Devices\XPTO\XPTODevice::class,
        ],
    ],
],
```

3. **Valide os paths TR-069 contra o equipamento real** (potências ópticas, índices de
   WLANConfiguration, namespace `InternetGatewayDevice` vs `Device.`).

### Convenção de nomes (classes, arquivos e pastas)

A auto-descoberta deriva a classe pelo **caminho do arquivo** — namespace, pasta e nome de
classe DEVEM bater (PSR-4), senão o handler é silenciosamente ignorado.

```
src/Vendors/{Vendor}/
├── {Vendor}Device.php                       MARCA   (abstract)        → ZTEDevice
└── Devices/{Model}/
    ├── {Model}Device.php                     MODELO  (wildcard '*')    → HG6245DDevice
    └── Firmware/{FwToken}/
        └── {Model}_{FwToken}Device.php       MODELO/FIRMWARE          → HG6245D_RP2616Device
```

- `{Model}` = product class do GenieACS (`HG6245D`, `F6201B`).
- `{FwToken}` = firmware **sem pontos** (`RP2616`; `V9.3.10P7N8` → `V9310P7N8`).
- Classe de firmware = **`{Model}_{FwToken}Device`** (um underscore). Ex.:
  `HG6245D_RP2616Device`, `F6201B_V9310P7N8Device`.
- `firmwareVersion()` retorna a string **real** (`'RP2616'`) — é a chave do registry.

---

## Resolução de parâmetros em camadas

O mapa de parâmetros é montado por herança, com a camada mais específica vencendo:

```
AbstractDevice                      (base)
  └─ ZTEDevice                      MARCA  → gpon_sn = X_ZTE-COM_GPONSN, ópticas, uptime…
       └─ F6201BDevice              MODELO → herda a marca + wifi/wan do modelo
            └─ F6201B_V9310P7N8Device  FIRMWARE → sobrescreve só o que muda no firmware
```

Cada camada faz `array_merge(parent::parameterMap(), [...])`. Exemplo: a chave `gpon_sn`
resolve para `X_ZTE-COM_GPONSN` no ZTE e para `DeviceInfo.SerialNumber` no FiberHome —
o mesmo código `$device->fetch()->gponSn()` funciona nos dois.

---

## Playground

Demo web interativa que roda o pacote de verdade (busca por serial/GPON SN, fetch
genérico, uptime, parser de payload, e a suíte de testes):

```bash
php -S localhost:8000 -t playground
# abra http://localhost:8000
```

## Testes

```bash
php vendor/bin/phpunit
```

Suíte unitária, sem dependência de rede (usa Client fakes).

---

## Licença

MIT.
