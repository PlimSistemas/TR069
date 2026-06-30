# CLAUDE.md

Guia de arquitetura do pacote **`plimsistemas/tr069`** — biblioteca Laravel para
gerenciar equipamentos via TR-069 usando a API do **GenieACS**.

> Este arquivo é lido por agentes (Claude Code) a cada sessão. Mantenha-o
> atualizado ao mudar a arquitetura.

---

## Visão geral

- **Tipo:** biblioteca Laravel (package), não uma aplicação.
- **Função:** abstrair o GenieACS para ler/escrever parâmetros TR-069 de ONTs/roteadores
  de forma **agnóstica de fabricante/modelo/firmware**.
- **PHP:** ^8.1 · **Laravel:** illuminate/support ^10|^11 · **HTTP:** Guzzle 7.
- **Namespace raiz:** `Plimsistemas\TR069\` → `src/` (PSR-4).
- **GenieACS:** `https://tr069.plim.net.br/api` (configurável via `TR069_BASE_URL`).

---

## Comandos

```bash
# Testes (PHPUnit) — toda a suíte
php vendor/bin/phpunit

# Um teste específico
php vendor/bin/phpunit --filter NomeDoTeste

# Relatório HTML de testes
php vendor/bin/phpunit --testdox-html build/test-report.html

# Playground web (demo interativa que roda o pacote de verdade)
php -S localhost:8000 -t playground   # abre http://localhost:8000
```

- Os testes são **unitários puros** (estendem `PHPUnit\Framework\TestCase`) e **não
  tocam a rede** — usam Client fakes. Ficam em `tests/Unit/` e o nome termina em `Test.php`.
- `tests/TestCase.php` é uma base Orchestra Testbench (para testes de integração Laravel);
  é abstrata — **nunca rode esse arquivo direto** (dá `Class not found`).
- O `playground/index.php` carrega o `.env` real e o pacote completo; resultados em modal.

---

## Componentes centrais

| Classe | Papel |
|---|---|
| [GenieACS/Client.php](src/GenieACS/Client.php) | Wrapper Guzzle da API GenieACS (devices, tasks). |
| [GenieACS/QueryBuilder.php](src/GenieACS/QueryBuilder.php) | Builder de query/projection da API. |
| [GenieACS/Responses/DeviceResponse.php](src/GenieACS/Responses/DeviceResponse.php) | Lê o JSON cru de um device (`getParameter()` por dot-notation). |
| [GenieACS/Responses/TaskResponse.php](src/GenieACS/Responses/TaskResponse.php) | Resposta de task (status, parameterValues). |
| [Device/DeviceInfo.php](src/Device/DeviceInfo.php) | DTO com id/manufacturer/oui/productClass/serial/softwareVersion. |
| [Device/DeviceRegistry.php](src/Device/DeviceRegistry.php) | Mapa `vendor → model → firmware → class`; resolve com fallback wildcard `*`. |
| [Device/DeviceDiscovery.php](src/Device/DeviceDiscovery.php) | Auto-descoberta dos handlers em `src/Vendors`. |
| [Device/AbstractDevice.php](src/Device/AbstractDevice.php) | Base de todo handler; `parameterMap()`, `get/set`, `fetch()`, `pathFor()`, **leitura de grupos** (`getLanConfig/getLanPorts/getHosts/getWanConnections`, `getObject*`, `refresh*`). |
| [Device/Data/](src/Device/Data/) | DTOs tipados de leitura: `LanConfig`, `LanPort`, `Host`, `WanConnection`. |
| [Device/ParameterFetch.php](src/Device/ParameterFetch.php) | Builder fluente para ler dados ATUALIZADOS (genérico). |
| [Device/DeviceReading.php](src/Device/DeviceReading.php) | Resultado tipado do fetch (`getUptime()`, `getTxPower()`, …). |
| [TR069Manager.php](src/TR069Manager.php) | Entry point: `findBySerial()`, `findByGponSn()`, `find()`, `devices()`. |
| [Facades/TR069.php](src/Facades/TR069.php) | Facade Laravel. |
| [TR069ServiceProvider.php](src/TR069ServiceProvider.php) | Bindings + merge descoberta/config + registro de vendors. |

---

## Resolução de parâmetros em CAMADAS (herança)

O coração do pacote. Cada chave amigável (ex.: `gpon_sn`, `wifi.2g.ssid`) é mapeada
para um path TR-069, e o mapa é montado por **herança de classes**, com a camada
**mais específica vencendo**:

```
AbstractDevice                 (base — parameterMap() retorna [])
  └─ {Vendor}Device            MARCA   — padrões do fabricante; define vendor()
       └─ {Model}Device        MODELO  — sobrescreve a marca; define model()
            └─ {Model}{Fw}Device  MODELO/FIRMWARE — sobrescreve o modelo; define firmwareVersion()
```

Cada nível **mescla** sobre o pai:

```php
protected function parameterMap(): array
{
    return array_merge(parent::parameterMap(), [
        // apenas o que muda NESTA camada (vence as de cima)
    ]);
}
```

`AbstractDevice::parameterMap()` é **concreto** (retorna `[]`) justamente para permitir
o `parent::parameterMap()` em cadeia. `pathFor(string $key): ?string` resolve uma chave
sem lançar (null se não mapeada) — usado pelo fetch genérico.

### Exemplo real — GPON SN difere por marca

| Camada | Classe | `gpon_sn` resolve para | valor real |
|---|---|---|---|
| Marca ZTE | [ZTEDevice](src/Vendors/ZTE/ZTEDevice.php) | `InternetGatewayDevice.DeviceInfo.X_ZTE-COM_GPONSN` | `ZTEGDB18E99D` |
| Marca FiberHome | [FiberHomeDevice](src/Vendors/FiberHome/FiberHomeDevice.php) | `InternetGatewayDevice.DeviceInfo.SerialNumber` | `FHTT953A2988` |

`F6201BDevice extends ZTEDevice` herda o `gpon_sn` da marca e só adiciona wifi/wan do
modelo. Um handler de firmware (`extends F6201BDevice`) poderia sobrescrever `gpon_sn`
caso aquele firmware exponha o valor em outro path — vencendo marca e modelo.

> ⚠️ ONT GPON (ZTE/FiberHome) tem potências ópticas e GPON SN; roteador Wi-Fi
> (Intelbras W5-1200F) não — nesses, as chaves ópticas simplesmente não são mapeadas
> e os getters retornam `null` (degradação graciosa).

---

## Chaves canônicas (genéricas, agnósticas)

Constantes em [ParameterFetch](src/Device/ParameterFetch.php). Cada handler mapeia no seu
`parameterMap()` (normalmente na camada de marca):

| Constante | Chave | Significado |
|---|---|---|
| `UPTIME` | `uptime` | Uptime (segundos) |
| `TX_POWER` | `optical.tx_power` | Potência óptica TX (dBm) |
| `RX_POWER` | `optical.rx_power` | Potência óptica RX (dBm) |
| `SW_VERSION` | `sw_version` | Versão de software |
| `HW_VERSION` | `hw_version` | Versão de hardware |
| `GPON_SN` | `gpon_sn` | GPON Serial Number |

---

## API genérica de dados ATUALIZADOS

Fluxo: **selecionar parâmetros → executar 1 task sem enfileirar → ler → limpar se falhar.**

```php
$reading = $device->fetch()           // ParameterFetch
    ->uptime()->txPower()->rxPower()
    ->swVersion()->hwVersion()->gponSn()
    ->timeout(25000)                  // opcional (ms)
    ->execute();                      // -> DeviceReading

$reading->getRxPower();   // ?float
$reading->getUptime();    // ?int
$reading->getGponSn();    // ?string
```

`ParameterFetch::execute()`:
1. Resolve cada chave canônica via `device->pathFor()` (ignora não mapeadas).
2. Dispara **UMA** task `getParameterValues` via `Client::executeTask()` (connection_request).
3. Em sucesso (HTTP 200), relê os valores via `Client::getDevice()` e devolve `DeviceReading`.
4. Em falha (device não respondeu / HTTP 202), a task é **deletada** e lança `TaskException`.

---

## API de leitura padronizada (config LAN / portas / hosts / WAN + DTOs)

Além do `fetch()` genérico (params escalares), o `AbstractDevice` expõe leitura de
**grupos inteiros** de configuração, agnóstica de fabricante, com retorno tipado.

```php
$cfg   = $device->getLanConfig();        // LanConfig (DTO)
$ports = $device->getLanPorts();          // Collection<int, LanPort>
$hosts = $device->getHosts();             // Collection<int, Host>  (clientes na LAN)
$wan   = $device->getWanConnections();    // Collection<int, WanConnection>
```

DTOs em [src/Device/Data/](src/Device/Data/) (`LanConfig`, `LanPort`, `Host`,
`WanConnection`): `readonly`, `fromArray()` com cast defensivo (bool/int/string),
`toArray()`, e um bag `extra` (+ `extra('chave')`) para campos de fabricante.
Filtros de Collection usam o **nome da propriedade** (camelCase, ex.: `interfaceType`),
não a chave snake_case do `toArray()`.

### ⚠️ Gotcha central: ler exige refresh + ler o DOCUMENTO

Criar uma task `getParameterValues`/`refreshObject` **retorna a task, não os valores**;
os valores caem no **documento** do device. Toda leitura faz: `executeTask(...)` síncrono
(connection_request + timeout) **e depois** `getDevice()->getParameter()`. Os antigos
`getMany`/`get`/`getPath` que liam da resposta da task voltavam vazios — hoje passam por
`readPaths()`. **`timeoutMs <= 0` PULA o refresh e lê só o cache do GenieACS** (instantâneo,
porém possivelmente defasado) — vale para todos os `get*`. O refresh ao vivo contata a ONU
(CWMP) e pode levar **vários segundos**.

### Padrão por grupo: `*Path()` + `*Map()` overridáveis em camadas

Mesma lógica do `parameterMap()`: cada grupo tem um path-base e um mapa canônico
(`chave_canônica → leaf`), sobrescritos por marca/modelo via `array_merge(parent::...)`:

| Grupo | Método | Path | Map (override) |
|---|---|---|---|
| Config LAN/DHCP | `getLanConfig()` → DTO | `lanConfigPath()` (LANHostConfigManagement) | `lanConfigMap()` — ZTE add `isp_dns` |
| Portas Ethernet | `getLanPorts()` → Collection | `lanPortsPath()` | `lanPortMap()` |
| Hosts conectados | `getHosts()` → Collection | `hostsPath()` | `hostMap()` |
| Conexões WAN | `getWanConnections()` → Collection | `wanRootPath()` | `wanConnectionMap()` — vlan/cos/service por marca |
| Voz / VoIP (SIP) | `getVoiceProfiles()` → Collection (+ `getVoiceLines(profile)`) | `voiceServicePath()` (VoiceService.1) | `voiceProfileMap()` + `voiceLineMap()` — standby/DTMF/IMS por marca |
| Wi-Fi (SSIDs) | `getWifiNetworks()` → Collection (+ `getWifiNetwork(i)`) | `wifiRootPath()` (WLANConfiguration) | `wifiNetworkMap()` — banda/largura X_ZTE por marca |

> **Wi-Fi (TR-098):** `getWifiNetworks()` lê o multi-instância `LANDevice.1.WLANConfiguration.{i}`
> (cada instância = um SSID numa banda) e devolve `Collection<int,WifiNetwork>`.
> Núcleo idêntico FiberHome×ZTE: `Enable`/`SSID`/`KeyPassphrase`/`Channel`/
> `AutoChannelEnable`/`Standard`/`BeaconType`/`SSIDAdvertisementEnabled`/
> `TransmitPower`/`TotalAssociations`/`BSSID`. Extras em `->extra` por override:
> ZTE `X_ZTE-COM_OperatingFrequencyBand`/`OperatingChannelBandwidth`/`MaximumClients`;
> FiberHome não tem extras de Wi-Fi (herda a base). **`band` ('2.4GHz'/'5GHz') é
> DERIVADO** de PossibleChannels/Channel (≥32 = 5G), não é uma leaf. Convenção de
> instâncias observada nos 3 modelos: **1–4 = 2.4 GHz, 5–8 = 5 GHz** (1ª de cada
> faixa = principal). A senha do Wi-Fi (`KeyPassphrase`) é LEGÍVEL (≠ senha SIP).
> Validado em HG6143D, F6600P e F670L (F670L com clientes reais associados).

> **Voz (TR-104):** `getVoiceProfiles()` percorre `VoiceService.1.VoiceProfile.{i}`
> e, dentro, `Line.{j}` (contas SIP), devolvendo `Collection<int,VoiceProfile>`
> onde cada perfil tem `->lines` (`Collection<int,VoiceLine>`). O núcleo
> (proxy/registrar/outbound, `Line.SIP.AuthUserName`/`URI`/`DirectoryNumber`,
> `Status`/`CallState`) é TR-104 padrão e **idêntico entre FiberHome e ZTE**;
> só as extensões proprietárias divergem e entram em `->extra` via override de
> marca: ZTE `SIP.X_ZTE-COM_Standby-*` + `DTMFMethodG711` + `RTP.VLANIDMark` +
> `CallingFeatures.*`; FiberHome `SIP.X_FH_Standby-*` + `SIP.X_FH_802-1pMark` +
> `Line.X_FH_IMS.*`. `enabled` deriva do enum `Enabled`/`Disabled`.
> ⚠️ `SIP.AuthPassword` é **write-only** (lê vazio) → não é mapeada na leitura.
> Validado em HG6143D, F6600P e F670L (F670L com linha registrada `Status=Up`).

Primitivos compartilhados: `refreshAndReadNode()` (refresh + lê nó cru, best-effort),
`getObject()` (instância única → leaves achatadas), `getObjectInstances()` (multi-instância
→ uma entrada por chave numérica), `flattenLeaves()` (descarta metadados `_*`, aninhados
viram `Stats.BytesSent`), `mapInstance()` (canônico→leaf, ausente=null).

### Refresh em lote (1 sessão CWMP)

`refresh(string|array $paths)` enfileira N `refreshObject` e dispara **uma** conexão
(`Client::executeTasks()` — o GenieACS roda todas as tasks pendentes na mesma sessão).
`refreshLan()` refresca config+portas+hosts num único contato; depois leia com `get*(0)`
(cache). Como cada `get*` ao vivo é lento, o padrão recomendado é: **job na fila roda o
refresh; a tela lê do cache**.

### Específicos de fabricante (overrides reais)

- **VLAN/COS/serviço WAN** são proprietários: `wanConnectionMap()` sobrescrito em
  `ZTEDevice` (`X_ZTE-COM_VLANID`/`_8021P`/`_ServiceList`, no nível da conexão) e
  `FiberHomeDevice` (`X_FH_WANGponLinkConfig.VLANID`/`.802-1pMark` + `X_FH_ServiceList`,
  no nível **WANConnectionDevice** — por isso `getWanConnections()` faz merge desse nível).
- **`isp_dns`** (LAN) só existe na ZTE → fica em `extra`, não promovido a campo do DTO.

### Detalhes que mordem (verificados em device real)

- **WAN é aninhado:** `WANDevice.{i}.WANConnectionDevice.{j}.WAN{PPP,IP}Connection.{k}`.
  `type` = `ppp`/`ip`; `mode` = `AddressingType` (STATIC/DHCP) ou `PPPOE` (derivado nas PPP);
  `gateway` = coalesce `DefaultGateway` (IP) ↔ `RemoteIPAddress` (PPP).
- **`cleanAddress()`**: IP/gateway/DNS valendo `0.0.0.0` (ou vazio) viram `null`; DNS também
  vira null se a conexão não está `Connected`, e é separado em `dns1`/`dns2`.
- **Hosts — `interface`:** resolvido da referência `Layer2Interface` para nome amigável
  (SSID do Wi-Fi ou nome da porta `eth2`). FiberHome reporta a ref **com ponto final**
  (`...Config.1.`) e ZTE **sem** → `getHosts()` faz `rtrim($ref, '.')` p/ casar com o
  `interface_ref` das portas.
- **WAN `index`** = nº do `WANConnectionDevice`: bate 1/2/3 no FiberHome, mas **repete (1,1,1)
  no ZTE** (todas as conexões sob `WANConnectionDevice.1`). Não é um índice único por conexão.

> Estes métodos vivem no `AbstractDevice` → valem p/ todos os handlers (FiberHome/ZTE/Intelbras)
> no namespace IGD, sem override de portas/hosts (validado em HG6143D, F6201B, F6600P).

---

## Conexão dinâmica (config em runtime)

A conexão GenieACS (base_url, username, password, timeout, verify_ssl) pode ser passada
**em runtime**, sem depender de `.env`/config — útil para multi-tenant (um ACS por provedor).

```php
$acs = TR069::connection([
    'base_url' => $tenant->acs_url,
    'username' => $tenant->acs_user,
    'password' => $tenant->acs_pass,
]);
$acs->findByGponSn('ZTEGDB18E99D')->fetch()->rxPower()->execute();
```

`TR069Manager::connection(array $config)` cria um novo manager com um `Client` para aquela
conexão, **reaproveitando o mesmo registry de handlers e os vendors** (que independem da
conexão). O `config/tr069.php` continua servindo de conexão padrão (opcional); a config
estática NÃO é obrigatória se você sempre usar `connection()`.

No playground a conexão é informada na UI (card "🔌 Conexão") e guardada na sessão — o
`.env` só prefilla os campos na primeira carga.

## Status online

Duas noções distintas — não confunda:

| Método | O que verifica | Como |
|---|---|---|
| `TR069::isOnline($deviceId)` / `$device->isOnline()` / `Client::isReachable()` | Device **acessível pelo ACS agora** (tempo real) | Dispara um connection_request com uma task leve de leitura; `true` se o device respondeu, `false` se não foi alcançado no timeout. |
| `$device->isWanConnected()` (handlers) | Status da conexão **WAN/internet** | Lê `wan.connection.status` (pode ser cacheado). |

`isOnline()` é a forma confiável de "está online?" porque força o device a falar com o ACS;
`isWanConnected()` pode estar desatualizado e mede outra coisa (a WAN, não a ligação ao ACS).

## Busca de devices

| Método (Manager / Facade) | Busca por | Campo GenieACS |
|---|---|---|
| `findBySerial($serial)` | Serial TR-069 | `_deviceId._SerialNumber` (regex `^...$`, case-insensitive) |
| `findByGponSn($gponSn)` | GPON SN | virtual param `VirtualParameters.GponSN` |
| `find($deviceId)` | ID GenieACS | query por `_id` |

> O GPON SN **difere** do serial TR-069 (ex.: ZTE F6201B → GponSN `ZTEGDB18E99D` ≠
> serial `ZTE0QT1R9X12701`). O `VirtualParameters.GponSN` é um virtual parameter
> criado no servidor GenieACS.

---

## Auto-descoberta de handlers

[DeviceDiscovery](src/Device/DeviceDiscovery.php) varre `src/Vendors`, instancia cada
classe concreta com um `DeviceInfo` dummy e lê `vendor()/model()/firmwareVersion()` para
montar o mapa `vendor → model → firmware → class` (mesma forma do config).

- Só registra quem **se autodescreve** (vendor/model não vazios) → exclui
  `GenericDevice` (retorna vazio) e classes abstratas (`ZTEDevice`, `FiberHomeDevice`).
- O [ServiceProvider](src/TR069ServiceProvider.php) mescla descoberto + config:
  `array_replace_recursive($discovered, $config['devices'])` — **o config tem precedência**.
- Liga/desliga por `config('tr069.auto_discover')` (env `TR069_AUTO_DISCOVER`, default `true`).

---

## Convenção de nomes (classes, arquivos e pastas)

A resolução em camadas é expressa pela **hierarquia de classes**, e a auto-descoberta
deriva a FQCN pelo **caminho do arquivo** — então namespace, pasta e nome de classe
DEVEM seguir o padrão abaixo (senão o handler é silenciosamente ignorado).

```
src/Vendors/{Vendor}/
├── {Vendor}Device.php                          MARCA   (abstract)  → ZTEDevice, FiberHomeDevice
└── Devices/{Model}/
    ├── {Model}Device.php                        MODELO  (wildcard '*') → HG6245DDevice, F6201BDevice
    └── Firmware/{FwToken}/
        └── {Model}_{FwToken}Device.php          MODELO/FIRMWARE → HG6245D_RP2616Device, F6201B_V9310P7N8Device
```

- **`{Model}`** = product class exatamente como o GenieACS reporta (`HG6245D`, `F6201B`).
- **`{FwToken}`** = firmware sanitizado, **só alfanumérico, sem pontos** (`RP2616`;
  `V9.3.10P7N8` → `V9310P7N8`). Usado na pasta e no nome da classe.
- **Classe de firmware** = `{Model}_{FwToken}Device` — **um underscore** separando modelo
  e firmware; o sufixo `Device` fica colado. Ex.: `HG6245D_RP2616Device`.
- **`firmwareVersion()` retorna a string REAL** (`'RP2616'`, `'V9.3.10P7N8'`) — é ela que
  vira a chave no registry; o `{FwToken}` da pasta/classe é apenas cosmético.
- **PSR-4 é obrigatório:** namespace = caminho da pasta, filename = nome da classe. Um
  arquivo na pasta errada (namespace ≠ path) não é carregável e a descoberta o ignora.

## Como adicionar suporte a um novo equipamento

1. **Handler de modelo** em `src/Vendors/{Vendor}/Devices/{Model}/{Model}Device.php`
   estendendo a base da marca (`extends ZTEDevice` / `FiberHomeDevice` / `AbstractDevice`):
   ```php
   class XPTODevice extends ZTEDevice {
       public function model(): string { return 'XPTO'; }
       public function firmwareVersion(): string { return $this->deviceInfo->softwareVersion ?? '*'; }
       protected function parameterMap(): array {
           return array_merge(parent::parameterMap(), [ /* específicos do modelo */ ]);
       }
   }
   ```
2. (Opcional) **Handler de firmware** em `.../{Model}/Firmware/{FwDir}/` estendendo o modelo,
   sobrescrevendo só o que muda + `firmwareVersion()` retornando a versão exata.
3. **Registro:** com `auto_discover` ligado, nada a fazer — é descoberto automaticamente.
   Para forçar/override, adicione em `config/tr069.php` → `devices.{vendor}.{model}.{firmware}`
   (use `*` como wildcard de firmware).
4. **Validar os paths TR-069 contra o device real** antes de produção (potências, índices
   de WLANConfiguration, namespace IGD vs Device., etc.).

---

## ⚠️ Gotchas da API GenieACS (verificados empiricamente)

- **NÃO** existe `GET /devices/<id>` → responde **405**. Para ler 1 device use
  `GET /devices/?query={"_id":"..."}&projection=...` ([Client::getDevice()](src/GenieACS/Client.php)).
- Para executar task **sem enfileirar** (síncrono), o query param é **`?connection_request`**
  (+ `&timeout=<ms>`), **não** `?connection`. Resposta: **200** = device executou na hora
  (valores já atualizados no banco); **202** = enfileirou (device inacessível) → deletar a task.
  Ver [Client::executeTask()](src/GenieACS/Client.php).
- Parâmetros são armazenados como objeto `{_value, _timestamp, _type, ...}`. `getParameter()`
  devolve o `_value` (folha) quando presente.
- Se o device estiver atrás de NAT/CGNAT e não responder ao connection_request a tempo,
  o valor "ao vivo" não atualiza — exibe-se o último valor informado (com timestamp/idade).

---

## Estrutura de diretórios

```
src/
  TR069Manager.php            Entry point (findBySerial/findByGponSn/find/devices)
  TR069ServiceProvider.php    Bindings + discovery + vendors
  Contracts/                  DeviceInterface, VendorInterface
  Device/                     DeviceInfo, DeviceRegistry, DeviceDiscovery,
                              AbstractDevice, ParameterFetch, DeviceReading
  Exceptions/                 DeviceNotFound, DeviceNotSupported, GenieACS, Task
  Facades/                    TR069
  GenieACS/                   Client, QueryBuilder, Responses/
  Vendors/
    AbstractVendor.php        Base de VendorInterface (matches por manufacturer)
    {Vendor}/{Vendor}Vendor.php   Identidade do fabricante (key/manufacturerNames)
    {Vendor}/{Vendor}Device.php   Camada MARCA da resolução de parâmetros
    {Vendor}/Devices/{Model}/...  Camadas MODELO e MODELO/FIRMWARE
    Generic/GenericDevice.php Fallback (acesso só por path cru; não auto-descoberto)
config/tr069.php              base_url, auth, auto_discover, devices[]
playground/index.php          Demo web (busca, fetch genérico, uptime, parser, testes)
tests/Unit/                   Testes unitários (Client fakes, sem rede)
```

---

## Convenções

- **Vendor key** = `strtolower(vendor())` (ex.: `'ZTE'` → `'zte'`); o `DeviceRegistry`
  normaliza ao registrar/resolver.
- Handlers de modelo expõem métodos de conveniência (`setWifi2gCredentials()`, etc.)
  além das chaves do `parameterMap()`.
- Ao mapear novos paths, **sempre validar com o GenieACS real** — cada firmware pode mudar
  os paths; por isso a homologação é por firmware e a resolução em camadas.
