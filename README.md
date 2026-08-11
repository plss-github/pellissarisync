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
| Tarefa | **somente quem lançou → outra ponta** | Chega como **tarefa de verdade**, com a duração; tarefas **privadas nunca saem**. Tornar uma tarefa privada depois esvazia a cópia já enviada |
| Custo | **somente quem lançou → outra ponta** | Itens da aba Custos: período, tempo, custo unitário/fixo/material |
| Solução | **somente quem resolveu → outra ponta** | Chega como **solução de verdade**, na aba Solução, e leva o chamado a *solucionado* |
| Aprovação | **pedido: de quem pediu; resposta: de quem respondeu** | Vira uma aprovação real quando o **e-mail do aprovador** casa com um usuário local; sem casar, pedido e resposta ficam como acompanhamentos informativos |
| Anexo | **somente quem anexou → outra ponta** | Bytes viajam no payload; o arquivo chega idêntico (mesmo SHA1). Inclui o arquivo enviado **na abertura** |
| Requerente e técnico | **bidirecional, aditivo** | Casados por **e-mail**; sem usuário local, o endereço vira ator de e-mail (como num chamado aberto por e-mail). Atores são adicionados, nunca removidos |
| Status | **bidirecional** | É o que conta ao cliente em que pé está o atendimento |
| Lixeira | **bidirecional** | Excluir de um lado manda o espelho para a lixeira do outro; restaurar traz de volta. **Purgar não é espelhado** — destruir dado na outra ponta não é atribuição de um espelho |

Relacionamento entre chamados não é espelhado: os ids não correspondem entre as
duas instalações e adivinhar o par pelo título produziria vínculos errados.

O espelho preserva a autoria e a linha do tempo da origem:

```
[Cliente X] Título original
──────────────────────────
Autor: Nome Sobrenome <email>
Data de criação: 10/08/2026 - 15:00:37
Data de modificação: 10/08/2026 - 15:04:14

<conteúdo original>
```

O bloco é remontado a cada atualização a partir do payload, então nunca empilha. A linha de modificação só aparece se houve alteração após a criação.

`{client}` é o **nome do agent como ele está cadastrado no destino** — na central, o nome do cliente, definido por quem vinculou o agent à entidade. Não é o nome da entidade na origem: aquele é só o que o cliente chamou a entidade dele, e vinha errado no prefixo. O nome da entidade de origem continua como último recurso, quando o cadastro do agent está sem nome.

## Instalação

```bash
php bin/console plugin:install pellissarisync -u glpi

# o papel vai pelo config:set do core, não pela CLI do plugin -- veja abaixo
php bin/console config:set --context=plugin:pellissarisync role master   # ou agent

php bin/console plugin:activate pellissarisync

# a partir daqui a CLI do plugin existe
php bin/console plugins:pellissarisync:configure --show
```

> **Por que o papel não vai pela CLI do plugin.** Há um ciclo: `plugin:activate`
> recusa ativar enquanto o papel não estiver escolhido
> (`plugin_pellissarisync_check_config()`), e o comando
> `plugins:pellissarisync:configure` só existe **depois** da ativação — o
> `CommandLoader` do console percorre `Plugin::getPlugins()`, que devolve apenas os
> plugins ativos. O `role` é texto puro na `glpi_configs`, então o `config:set` do
> core resolve; o token de registro, que é cifrado com a chave da instância,
> continua indo pela CLI do plugin.
>
> Pela interface não há ciclo: **Configurar → Plug-ins**, ícone de chave inglesa na
> linha do plugin. Funciona mesmo em *Instalado / não configurado*, porque na web o
> GLPI carrega plugins nesse estado e mostra o botão de configuração.

### No master

1. Defina a categoria que receberá os espelhos e copie o **token de registro** exibido na tela.
2. Quando um agent se conectar, ele aparece como *pendente*: associe-o a uma **entidade (cliente)**, defina o **nome do cliente** (é ele que aparece no prefixo `[cliente]`) e, se quiser, o **grupo e o técnico encarregados**; depois mude o vínculo para *Vinculado*. Enquanto pendente, o master recusa os chamados (HTTP 409) e o agent os mantém na fila — é a trava que impede chamados de cliente caírem na entidade errada.

### No agent

1. Informe a URL do master, a URL desta instância (como o master a alcança) e cole o token de registro.
2. **Conectar ao master** — o token é trocado uma única vez por credenciais dedicadas a este agent.
3. Escolha a categoria que dispara o espelhamento (`Suporte Pellissari - Fluídez Digital` é criada na instalação).
4. **Gerar chamado de teste** valida a cadeia inteira.

## Regras de negócio e atribuição

Um chamado que chega do cliente **passa pelas regras de negócio do master**. Para a
central ele é um chamado como qualquer outro: precisa ser roteado, priorizado,
ganhar SLA e ser atribuído. Do lado do agent as regras continuam desligadas — ali o
chamado é a cópia de algo que a central já processou, e deixar as regras do cliente
reescreverem a cópia faria as duas pontas discordarem.

Duas coisas seguem valendo mesmo com as regras ligadas:

- **A entidade é intocável.** Se uma regra mover o espelho para outra entidade, o
  plugin o devolve para a entidade do cliente e registra no log. A entidade não é um
  campo qualquer aqui: é o vínculo com o cliente, escolhido por quem vinculou o
  agent. Uma regra de roteamento colocaria o chamado do cliente A dentro do cliente
  B — um vazamento com cara de automação.
- **`_skip_auto_assign` continua ligado**, que é a atribuição automática de
  entidade do core.

Nas atualizações do espelho as regras continuam desligadas, de propósito: uma
mudança que a regra fizesse ali aconteceria dentro da trava de reentrância e não
voltaria para a outra ponta, criando divergência silenciosa. Dá para desligar tudo
em **Configurar → Plugins → Pellissari Sync**, se alguma regra se comportar mal.

### Atribuídos

Na tela do master há **Atribuição padrão** — técnicos e grupos, os mesmos campos do
chamado de verdade (usuários filtrados por `own_ticket`, grupos por `is_assign`).
Vale para todo chamado que chegar.

Por cliente, o mesmo par de campos existe na tela do agent, em **Atribuição para
este cliente**, e **tem precedência** sobre o global: assim que um chamado do
cliente X abre, ele já sai atribuído ao grupo e ao técnico encarregado dele. Deixar
os dois vazios significa "usa o global", não "ninguém".

A atribuição é aplicada depois dos atores que vieram do cliente e depois das regras,
então ela soma em vez de competir. Em seguida o conjunto de atores volta para o
cliente, para ele ver quem está com o chamado — só usuários, por e-mail; grupo não
tem contrapartida na outra instalação.

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
| `..._agents` | A outra ponta: uuid, URL, credenciais, entidade/cliente, vínculo e a atribuição desse cliente (`assign_users`, `assign_groups`) |
| `..._mirrors` | Vínculo do chamado; a coluna `origin` decide quem pode propagar conteúdo |
| `..._mirrorfollowups` | Vínculo do que **chega como acompanhamento** sendo outra coisa na origem; `source_itemtype` separa os casos, já que uma solução #5 e um acompanhamento #5 têm o mesmo id em tabelas diferentes |
| `..._mirroritems` | Vínculo dos itens que **mantêm o itemtype nas duas pontas**: tarefa, custo, aprovação e solução. Tabela separada porque a de acompanhamentos registra uma tradução, e a coluna `itilfollowups_id` dela não pode honestamente guardar o id de um custo |
| `..._mirrordocuments` | Mapeia o anexo local ao id dele na outra ponta — o GLPI usa a mesma tabela `glpi_documents` nas duas, então os ids necessariamente divergem |
| `..._outbox` | Fila de saída com estado, tentativas e backoff |
| `..._inbox` | Registro de idempotência de entrada |

## Compatibilidade GLPI 10 / 11

Todas as divergências ficam em `src/Compat.php`, em vez de espalhadas pelo código. As que importam:

| Tema | GLPI 10 | GLPI 11 |
|---|---|---|
| Endpoint sem sessão | `$SECURITY_STRATEGY = 'no_check'` + `inc/includes.php` | `SessionManager::registerPluginStatelessPath()` no `boot()` |
| Texto rico | armazenado **HTML-encoded** (`Sanitizer`) | armazenado cru |
| Direitos na escrita | `add()` não os verifica, mas **`Ticket::update()` sim** | `Session::callAsSystem()` |
| Escape no `$DB->insert()` | **não escapa** — espera valor já escapado | `quoteValue()` escapa toda string |
| Alvo da aprovação | `users_id_validate` | `itemtype_target` + `items_id_target` |
| Cliente HTTP | Guzzle direto | `Toolbox::getGuzzleClient()` (aplica proxy) |
| Resposta do endpoint | `header()` + `echo` | retorna um `Response` do Symfony |

Três dessas são silenciosas e cada uma custou um sintoma diferente:

**Texto rico.** Sem a tradução, um chamado do GLPI 10 chegaria no 11 como
`&#60;p&#62;...` e o inverso apareceria com as tags visíveis para o cliente.

**Direitos na escrita.** No GLPI 10, `CommonITILObject::handleTemplateFields()`
descarta *todos* os campos exceto `id` quando quem escreve não tem direito de
UPDATE em chamados, e então recusa o update porque só sobrou o id. Como o endpoint
não tem sessão, **nenhuma atualização de status ou de texto era aplicada num agent
GLPI 10** — criações, acompanhamentos e tarefas funcionavam, o que fazia o
espelhamento parecer saudável. É por isso que o cliente reabrir um chamado não
reabria nada: o chamado dele nunca tinha chegado a *solucionado*. `Compat::asSystem()`
concede os direitos de chamado pela duração da aplicação; a válvula do core
(`Session::isCron()`) não serve, porque no GLPI 10 ela também exige CLI ou
`/cron.php`.

**Escape no banco.** O construtor de consultas do GLPI 10 interpola strings como
vêm. Um payload JSON gravado na `outbox` perdia as contrabarras — `class=\"x\"`
virava `class="x"` e o payload deixava de ser JSON válido, e a outra ponta
respondia *unknown mirrored ticket*. Pior: um **apóstrofo** no texto encerrava o
literal SQL, o INSERT falhava e o evento desaparecia sem deixar rastro na fila.
`Compat::escapeForDb()` cobre as escritas em SQL cru do plugin.

Ainda no tema aprovação: as duas versões condicionam os campos da resposta a
`Session::getLoginUserID()` ser o aprovador e, quando não é, **descartam
silenciosamente** `status` e `comment_validation` — o update "dá certo" sem mudar
nada. A resposta é aplicada via `Compat::asUser()`, como o aprovador local, que é
de quem a resposta realmente é.

## Log

`GLPI_LOG_DIR/pellissarisync.log` — inclusive as recusas de propagação, com o motivo:

```
content edit not propagated (not the owner) {"tickets_id":3,"origin":"agent","local_role":"master"}
```
