# Imagine Sites — briefing Unicode e validação por campo

## Problemas corrigidos

O formulário do briefing usava `maxlength` do navegador, cuja contagem em UTF-16 divergia da contagem de codepoints do domínio. Um campo de120 caracteres podia impedir120 emojis simples válidos antes de chegar ao servidor. Os cinco campos agora apresentam seus limites e recebem a validação do servidor, sem depender de JavaScript.

O domínio também aceitava NUL dentro de um texto. Esses bytes não são válidos no armazenamento PostgreSQL e faziam uma falha de conteúdo chegar ao tratamento genérico de autorização. NUL em extremidades podia ainda desaparecer no middleware de trim, transformando entrada malformada em conteúdo aceito.

Branch `feature/sites-brief-unicode-validation-2026-11-18`, sobre a PR #494 / `c85657477c50aa1ba66b4fc2ef0af134c5c25686`. A implementação altera somente a validação e apresentação de briefing do candidato existente; não define briefing oficial do Portal.

## Comportamento

| Campo | Limite existente preservado |
| --- | --- |
| Marca | 120 codepoints |
| Chamada principal | 180 codepoints |
| Descrição | 600 codepoints |
| Chamada do botão | 80 codepoints |
| Benefícios | Um a oito;180 codepoints por item;1500 no campo de formulário |

Emojis simples de quatro bytes contam como um codepoint. Acentos combinados e emojis compostos podem ocupar mais de um. Não se aplica normalização NFC/NFD, remoção de acentos ou mudança de ordem dos benefícios. O envelope HTTP continua limitado a32768 bytes, suficiente para os limites válidos existentes.

`SiteBrief` recusa NUL nos textos e benefícios, também para chamadas diretas ao domínio. `PreviewBriefInput` identifica conteúdo malformado no campo correspondente antes de dividir e aparar os benefícios. A substituição mínima de `TrimStrings` preserva somente os cinco campos que contenham NUL para que sejam recusados; entradas válidas continuam usando o comportamento original. As exceções de busca e nota permanecem próprias.

Uma resposta422 conserva todos os outros campos. Texto válido acima do limite permanece inteiro para o autor encurtar. Somente o campo que contém NUL ou UTF-8 malformado é limpo, com orientação explícita para preenchê-lo novamente. Identidade, pesquisa e `expected_revision` não entram nesse reaproveitamento: a revisão continua vindo do estado corrente consultado pelo servidor. Nenhum conteúdo é colocado no cookie de sessão.

A ajuda de cada campo e seu erro são associados por `aria-describedby`, com `aria-invalid` quando necessário. O campo não usa `maxlength` em UTF-16. A mensagem ao usuário descreve o problema sem exigir conhecimento de codificação.

## Verificação

Após incorporar a correção de sessão da busca, a suíte local completa passou com **134 testes/1186 assertions/27 skips explícitos** das integrações que exigem PostgreSQL ou opt-in. Os testes novos incluem domínio, parser, a pilha real de middleware global, preservação segura e renderização Blade; notas, busca e CSRF permanecem cobertos pelas regressões existentes.

A fixture SQL acrescenta **14 verificações esperadas**, ainda pendentes de execução: os dois produtos com todos os campos no limite de quatro bytes, round-trip de JSONB/HTML/SHA, comparação histórica, composição Unicode e CRLF, isolamento entre empresas e recusas sem escrita. O contador agregado anterior não foi reescrito.

A jornada HTTP cria e edita pelo formulário real com JavaScript desativado, verifica texto válido acima do limite, digests e ausência de revisão extra. Negativos de NUL usam corpos HTTP com `%00` explicitamente conferidos; a resposta422 real é renderizada no mesmo navegador para verificar a retenção dos campos. Isso evita depender de como a automação de teclado trata um caractere de controle. UTF-8 malformado também é enviado como bytes URL-encoded exatos. Há capturas desktop/mobile e correção posterior com a mesma sessão protegida.

O workflow permanece restrito a repo, draft, branch e SHA exato da base. Reexecuta o workspace e registry em PHP8.3/8.4, o upgrade gerenciado9+76 e o navegador HTTPS/FPM/PG18 descartável. **CI desta entrega ainda pendente.** Não foi iniciado PostgreSQL local.

## Fronteiras

Nenhuma migration, role, grant, versão de contrato comercial, autoridade Portal ou worker é criada. O preview continua `demonstration_only`; os dez gates operacionais permanecem BLOCKED. Não houve merge, deploy, execução na VPS/18090, pagamentos, aceite de cliente, DNS ou uso de dados reais.
