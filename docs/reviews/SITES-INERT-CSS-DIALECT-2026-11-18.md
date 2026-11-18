# Vocabulário CSS do artefato inerte

## Falha e decisão

A branch `fix/sites-inert-css-dialect-2026-11-18` deriva da #538 /
`cb300444b57aa8b6a314855a6e1a403fc4ddfbed`. Depois da correção de escapes e
`image-set()` da #534, a auditoria ainda aceitava recursos descritos por outras
funções CSS. A reprodução acrescentou uma declaração ao único bloco de estilo
de cada produto e consultou `InertPreviewAudit::issues()` em memória.

| Declaração acrescentada | Resultado anterior nos dois produtos |
| --- | --- |
| `background-image: image("/inert-audit-probe.png")` | Nenhum problema apontado |
| `background-image: src("/inert-audit-probe.png")` | Nenhum problema apontado |
| `--asset: "/inert-audit-probe.png"; background-image: src(var(--asset))` | Nenhum problema apontado |
| `background-image: SRC("/inert-audit-probe.png")` | Nenhum problema apontado |

Cada caso alterou exatamente um fechamento `style` e produziu outro SHA-256.
**Não houve execução em navegador ou requisição de rede observada.** A
reprodução demonstra a decisão da auditoria; a semântica dos recursos vem das
especificações primárias:

- [CSS Images 4, Working Draft de 30/09/2025, §2.5](https://www.w3.org/TR/2025/WD-css-images-4-20250930/#image-notation)
  admite URL ou string como origem de `image()`; uma string representa URL.
- [CSS Values 4, Working Draft de 12/03/2024, §4.5](https://www.w3.org/TR/2024/WD-css-values-4-20240312/#urls)
  define `src()` como forma de referência URL, incluindo substituição por
  `var()` em seus argumentos.
- [CSS Syntax 3, Candidate Recommendation Draft de 01/10/2026](https://www.w3.org/TR/2026/CRD-css-syntax-3-20261001/#consume-ident-like-token)
  distingue função pelo parêntese imediatamente após o identificador e trata
  strings, comentários e at-keywords separadamente.

O renderer usa um conjunto pequeno e conhecido. A decisão é permitir somente
suas cinco funções — `clamp`, `linear-gradient`, `minmax`, `repeat`, `rgba` — e
a at-rule `media`. O custo é explícito: construções benignas fora desse
conjunto, como `calc()`, `var()`, `rgb()` ou `@supports`, precisam de revisão
do renderer e da política antes de serem aceitas.

## Implementação e compatibilidade

O [scanner privado](../../src/Production/InertPreviewAudit.php) percorre apenas
o conteúdo de `style`. Ele separa strings e comentários, reconhece a palavra
inteira (incluindo bytes não ASCII), verifica at-keywords e continua pelos
argumentos das funções permitidas para conferir chamadas aninhadas. Recusa
NUL e strings/comentários não terminados. Os bloqueios anteriores permanecem.
Não é um parser completo nem uma validação de toda a gramática CSS.

As mesmas expressões continuam aceitas como texto escapado nos cinco campos
do briefing e na meta description. A auditoria não reescreve HTML. Renderer,
vetores, digest, contrato do pacote, SQL e migrations permanecem iguais.
Os consumidores existentes aplicam a política ao conferir o artefato; não há
migração automática de bytes ou planos históricos.

| Vetor confirmado | Bytes | SHA-256 preservado |
| --- | ---: | --- |
| Atual, desde #524 | 5223 | `ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e` |
| Histórico #517/#518, reconstruído somente em memória | 5167 | `12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15` |

Esses vetores delimitam a compatibilidade comprovada; não representam todo
artefato externo ou toda versão histórica possível.

## Evidência desta entrega

| Verificação local | Resultado observado |
| --- | --- |
| [F27](../../tests/f27-offline-preview-audit.php) | **260 PASS**: 152 anteriores + 108 novos |
| [Registro privado](../../tests/release-registration-offline.php) | **293 PASS**: 279 anteriores + 14 novos |
| Lint dos três arquivos PHP e diffcheck | **PASS** |
| Suíte geral Laravel, sem cache de resultados | **296 testes / 4705 assertions / 27 skips PASS** |
| Laboratório sintético de domínio | **32 checks PASS** |

Os testes distinguem literais de chamadas, incluem maiúsculas, aninhamento,
at-rules fora do dialeto e terminação inválida. Os pacotes negativos recebem
digest recalculado. No registro, tamanho e compromisso também são recalculados
e `plan`/`record` precisam recusar antes de qualquer acesso à PDO.

O [workflow F1-24](../../.github/workflows/sites-f1-24-release-bundle.yml)
exige os novos contadores em PHP 8.3/8.4, além dos vinte checks de pacote,
recusa sem opt-in, dois pacotes privados com `publishable=false` e cleanup.
**CI próprio ainda pendente.** Esta mudança não demanda nova execução de
PostgreSQL ou navegador; preserva a prova separada dos contratos anteriores.

Os dez gates continuam BLOCKED e AGI-11-RB01 desabilitado. O dialeto permanece
específico de prévias inertes; a auditoria não constitui sanitização genérica
nem aprovação de publicação.
