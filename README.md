# Pellissari Sync

Espelha chamados entre a instalação GLPI do cliente (**agent**) e a central de suporte da Pellissari (**master**).

Um único código-base roda nas duas pontas; o papel escolhido na configuração decide quais telas e quais gatilhos ficam ativos.

Autor: **Ampris**. Requer **GLPI 10.0 ou 11.x** — clientes ainda em 10 podem espelhar contra um master em 11, e o caminho cross-version é exercitado nos testes.

## A regra central: propagação por origem

Quem cria um conteúdo é o único dono dele.

| O que | Direção | Observação |
|---|---|---|
| Título e descrição | **somente origem → destino** | Editar do lado que não é o dono fica local e nunca é propagado |
| Acompanhamento | **somente quem escreveu → outra ponta** | Notas **privadas nunca saem** da instância onde foram escritas |
| Solução | **somente quem resolveu → outra ponta** | Chega como acompanhamento marcado com **Solução** em negrito |
| Anexo | **somente quem anexou → outra ponta** | Bytes viajam no payload; o arquivo chega idêntico (mesmo SHA1) |
| Status | **bidirecional** | É o que conta ao cliente em que pé está o atendimento |

O espelho preserva a autoria e a linha do tempo da origem:

```
[Cliente X] Título original
──────────────────────────
Autor: Nome Sobrenome <email>
Data de criação: 10/08/2026 - 15:00:37
Data de modificação: 10/08/2026 - 15:04:14

<conteúdo original>
```

O bloco é remontado a cada atualização a partir do payload, então nunca empilha. A linha de modificação só aparece se houve alteração após a criação. `{client}` vem do nome da entidade **na origem**.

## Instalação

```bash
php bin/console plugin:install pellissarisync -u glpi
# escolha o papel (o plugin fica "a configurar" até isso)
php bin/console plugins:pellissarisync:configure --role=master   # ou --role=agent
php bin/console plugin:activate pellissarisync
```

> O papel precisa ser definido antes de ativar. Pela interface: **Configurar → Plugins → Pellissari Sync**.

### No master

1. Defina a categoria que receberá os espelhos e copie o **token de registro** exibido na tela.
2. Quando um agent se conectar, ele aparece como *pendente*: associe-o a uma **entidade (cliente)** e mude o vínculo para *Vinculado*. Enquanto pendente, o master recusa os chamados (HTTP 409) e o agent os mantém na fila — é a trava que impede chamados de cliente caírem na entidade errada.

### No agent

1. Informe a URL do master, a URL desta instância (como o master a alcança) e cole o token de registro.
2. **Conectar ao master** — o token é trocado uma única vez por credenciais dedicadas a este agent.
3. Escolha a categoria que dispara o espelhamento (`Suporte Pellissari - Fluídez Digital` é criada na instalação).
4. **Gerar chamado de teste** valida a cadeia inteira.

## Segurança

- Endpoint próprio (`front/api.php`), declarado *stateless* em `plugin_pellissarisync_boot()`: sem sessão, sem cookie, sem CSRF — a autenticação é toda do plugin.
- Cada requisição carrega uuid + token e é assinada com **HMAC-SHA256 sobre o corpo cru**; a verificação usa `hash_equals`.
- O handshake não usa o token como *bearer*: ele é a **chave HMAC** daquela requisição, provando posse sem trafegar como credencial reutilizável.
- Tokens e segredos ficam cifrados no banco (`GLPIKey`), como o core faz com `glpi_apiclients.app_token`.
- Conteúdo HTML recebido da outra ponta passa por `RichText::getSafeHtml()`; o cabeçalho de autoria passa por `htmlescape()`.

## Entrega confiável

Toda alteração é enfileirada na `outbox` e enviada na hora. Se a outra ponta estiver fora do ar, a linha fica pendente e o `CronTask` reenvia com backoff exponencial (60s → 1h, 8 tentativas). A operação do usuário **nunca falha** por causa do espelhamento.

Contra duplicatas há três camadas: guarda de reentrância por requisição, marcador `_psync_apply` nas escritas do próprio plugin, e a tabela `inbox` de idempotência que cobre reentregas entre requisições.

## Linha de comando

```bash
php bin/console plugins:pellissarisync:configure --show
php bin/console plugins:pellissarisync:configure --ping=master     # ou --ping=<id>
php bin/console plugins:pellissarisync:configure --link-agent=1 --entity=3
php bin/console plugins:pellissarisync:configure --test-ticket --user=glpi
php bin/console plugins:pellissarisync:configure --flush
```

Existe porque o token de registro é cifrado com a chave de cada instância: só pode ser lido de volta pela API do próprio plugin, nunca por SQL.

No **GLPI 10** o `su` da imagem oficial limpa o ambiente e deixa `GLPI_MARKETPLACE_DIR` apontando para um diretório inexistente, o que quebra o `bin/console`. Informe a variável na chamada:

```bash
su www-data -s /bin/bash -c \
  'GLPI_MARKETPLACE_DIR=/var/www/glpi/marketplace php bin/console plugins:pellissarisync:configure --show'
```

## Tabelas

| Tabela | Papel |
|---|---|
| `..._agents` | A outra ponta: uuid, URL, credenciais, entidade/cliente, vínculo |
| `..._mirrors` | Vínculo do chamado; a coluna `origin` decide quem pode propagar conteúdo |
| `..._mirrorfollowups` | Vínculo dos acompanhamentos e soluções; `source_itemtype` separa os dois, já que uma solução #5 e um acompanhamento #5 têm o mesmo id em tabelas diferentes |
| `..._mirrordocuments` | Mapeia o anexo local ao id dele na outra ponta — o GLPI usa a mesma tabela `glpi_documents` nas duas, então os ids necessariamente divergem |
| `..._outbox` | Fila de saída com estado, tentativas e backoff |
| `..._inbox` | Registro de idempotência de entrada |

## Compatibilidade GLPI 10 / 11

Todas as divergências ficam em `src/Compat.php`, em vez de espalhadas pelo código. As que importam:

| Tema | GLPI 10 | GLPI 11 |
|---|---|---|
| Endpoint sem sessão | `$SECURITY_STRATEGY = 'no_check'` + `inc/includes.php` | `SessionManager::registerPluginStatelessPath()` no `boot()` |
| Texto rico | armazenado **HTML-encoded** (`Sanitizer`) | armazenado cru |
| Direitos na escrita | `add()` não os verifica | `Session::callAsSystem()` |
| Cliente HTTP | Guzzle direto | `Toolbox::getGuzzleClient()` (aplica proxy) |
| Resposta do endpoint | `header()` + `echo` | retorna um `Response` do Symfony |

A tradução de texto rico é a mais silenciosa: sem ela, um chamado do GLPI 10 chegaria no 11 como `&#60;p&#62;...` e o inverso apareceria com as tags visíveis para o cliente.

## Log

`GLPI_LOG_DIR/pellissarisync.log` — inclusive as recusas de propagação, com o motivo:

```
content edit not propagated (not the owner) {"tickets_id":3,"origin":"agent","local_role":"master"}
```
