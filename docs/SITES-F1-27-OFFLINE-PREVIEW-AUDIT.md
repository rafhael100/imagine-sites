# F1-27 — Auditoria offline das prévias não publicáveis (Onepage/Landing)

**Escopo:** reforçar a validação de arquivos sintéticos F1-24 para bloquear estruturas ativas e destinos externos na marcação/CSS mesmo quando um artefato HTML foi modificado e seus hashes SHA-256 foram recalculados.

## Implementação

- `InertPreviewAudit` valida exclusivamente o HTML de teste emitido pelo renderer atual, usando uma **política lexical restritiva**: tipo de produto, estrutura mínima, uma só H1, cinco metadados esperados, CSP e robots exatamente fixados, âncoras internas conhecidas, nenhuma tag desconhecida, atributo ativo, destino externo ou CSS remoto.
- O construtor e o verificador do `SyntheticReleaseBundle` passam pela auditoria **antes** de criar ou validar o pacote.
- A suíte `tests/f27-offline-preview-audit.php` prova aprovação de uma Onepage e uma Landing Page fictícias, aceitação de texto HTML escapado e rejeição adversarial de scripts, formulários, meta refresh, stylesheet, CSS `@import/url()`, imagem remota, atributos de evento, links externos, CSP/robots adulterados, markup malformado, alteração de H1 e remoção do aviso de prévia.
- Testes incluem tentativas com o **hash do conteúdo alterado recalculado** para provar que integridade de bytes isolada não torna conteúdo seguro.
- CI PHP 8.3/8.4 sem infraestrutura, pagamentos, credenciais nem dados reais.

## Correção de contexto textual — 18/11/2026

O workspace aceita texto simples e o renderer o escapa. A auditoria procurava URLs e nomes semelhantes a handlers em todo o HTML, incluindo texto visível e o valor da meta description. Assim, descrições como `https://example.invalid` ou `onboarding=aberto` podiam ser persistidas e visualizadas, mas a comparação de versões recusava o mesmo artefato.

A auditoria agora verifica tags e CSS separadamente do texto escapado. Somente o valor da meta description com o formato canônico exato é neutralizado numa cópia de auditoria; a tag original continua submetida à validação dos cinco metadados. Todos os demais atributos, links, elementos, CSS, CSP e robots conservam as restrições existentes. URLs e palavras como `onclick=` escritas como conteúdo não criam navegação, requisição ou execução.

O HTML original não é reserializado, normalizado ou alterado. Renderer, bytes persistidos, SHA-256 e cálculo dos pacotes permanecem iguais. F24 passa a aceitar `javascript:` quando é texto escapado e mantém a recusa quando é o destino de um link real. F27 cobre os cinco campos, ambos os produtos, aspas, Unicode e quebras de linha, além de atributos reais, metadados extras, links ativos e CSS inseridos ao lado de texto literal. O contrato separado de entrada `StructuredCopyCandidate` continua inalterado.

## Correção de referências CSS — 18/11/2026

A reprodução em memória confirmou que a auditoria recusava `url("/inert-audit-probe")`, mas aceitava `u\72l("/inert-audit-probe")`, `image-set("/inert-audit-probe" 1x)` e `-webkit-image-set("/inert-audit-probe" 1x)` no bloco de estilos. Não houve execução em navegador nem requisição de rede observada. Conforme CSS Syntax Level 3, escapes são resolvidos ao [formar identificadores](https://www.w3.org/TR/css-syntax-3/#consume-ident-sequence), antes do [reconhecimento de `url()`](https://www.w3.org/TR/css-syntax-3/#consume-ident-like-token). A [versão de 30/09/2025 de CSS Images Level 4](https://www.w3.org/TR/2025/WD-css-images-4-20250930/), §2.4 e Apêndice A, define as strings de `image-set()` como URLs e a variante WebKit como alias.

O dialeto fixo passa a recusar barras invertidas **somente dentro de `style`**, além de `image-set()` e seu alias com comparação sem distinção de maiúsculas. Os estilos atuais e históricos suportados não precisam desses recursos. Texto literal nos cinco campos e na meta description continua permitido, incluindo barras e nomes dessas funções. Renderer, vetores, bytes históricos, digests e cálculo dos pacotes não mudam; a auditoria não reescreve o HTML.

As provas locais finais passaram com **152 checks F27** e **279 asserções de registro**, incluindo digest/compromisso recalculados e recusa antes de qualquer chamada à PDO. A suíte geral passou com **296 testes / 4705 asserções / 27 skips**, e o domínio sintético com **32 checks**. A #534 confirmou esses contadores em PHP 8.3/8.4, com 12/12 checks SUCCESS; o [relatório da correção](reviews/SITES-INERT-CSS-ESCAPES-2026-11-18.md) registra o recorte, as fontes e os limites da reprodução.

## Vocabulário CSS fixo — 18/11/2026

Após o bloqueio de escapes e `image-set()`, a reprodução em memória ainda
aceitou `image("/inert-audit-probe")`, `src("/inert-audit-probe")` e
`src(var(--asset))` em ambos os produtos. As especificações definem strings de
`image()` e argumentos de `src()` como referências de recurso. A reprodução
não executou navegador e não observou uma requisição.

A auditoria passa a admitir somente as funções `clamp`, `linear-gradient`,
`minmax`, `repeat` e `rgba` e a at-rule `media`. Strings e comentários são
separados dos identificadores; funções exigem parêntese imediato e as chamadas
aninhadas são conferidas. NUL, strings com quebra de linha e strings/comentários
não terminados são recusados. O scanner confere vocabulário, sem implementar
toda a gramática CSS. Funções benignas como `calc()`, `var()` e `rgb()` também
exigem uma futura revisão explícita do dialeto.

**260 checks F27 e 293 de registro offline** passaram localmente. Os testes
preservam os vetores de 5223 e 5167 bytes com seus hashes originais, aceitam as
mesmas palavras como texto escapado e recusam pacotes com digest recalculado.
O CI desta ampliação permanece pendente. [Decisão, fontes primárias e prova](reviews/SITES-INERT-CSS-DIALECT-2026-11-18.md).

## Limites de segurança

Este mecanismo **não é um sanitizer HTML genérico nem prova AppSec**. Foi deliberadamente desenhado apenas para o template fixo, offline, sem formulários/terceiros e `publishable=false`. Outros layouts legítimos poderão ser rejeitados e precisam de um fluxo de revisão próprio. Um parser HTML/browser, análise de acessibilidade e segurança de produção, CSP real nos headers, testes de mobile e revisão de licenças continuam pendentes antes de expor qualquer página a clientes.

Não implementa geração real de IA, publisher Cloudflare, navegador, DNS, retenção LGPD, CRM, sinalização `ENTREGUE` do Portal nem autorização de produção. O `config/operational-readiness.json` segue BLOCKED, com `main` e VPS intocadas, AGI-11-RB01 desabilitado.

Referência: [SITES-OP03](https://github.com/rafhael100/imagine-sites/issues/35) e [SITES-OP05](https://github.com/rafhael100/imagine-sites/issues/37). Critérios de aceite permanecem abertos.
