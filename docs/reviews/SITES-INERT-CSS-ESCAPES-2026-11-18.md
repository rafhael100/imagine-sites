# Auditoria do CSS inerte: escapes e referências de imagem

## Defeitos reproduzidos

A candidata `fix/sites-inert-css-escapes-2026-11-18` corrige duas formas de
referência CSS que a [auditoria existente](../../src/Production/InertPreviewAudit.php)
aceitava. A reprodução usou o HTML sintético do renderer em memória, acrescentou
uma declaração no bloco `style` e consultou `InertPreviewAudit::issues()`.

| Conteúdo acrescentado ao CSS | Resultado anterior observado |
| --- | --- |
| `background-image: url("/inert-audit-probe")` | Recusa `active_css_or_style_structure` |
| `background-image: u\72l("/inert-audit-probe")` | Nenhum problema apontado |
| `background-image: image-set("/inert-audit-probe" 1x)` | Nenhum problema apontado |
| `background-image: -webkit-image-set("/inert-audit-probe" 1x)` | Nenhum problema apontado |

Os destinos relativos isolam a falha: não contêm a grafia `http://` ou
`https://`, cuja detecção poderia esconder a aceitação da estrutura CSS. O HTML
original, sem as declarações, passou na mesma reprodução. **Não houve execução
em navegador nem requisição de rede observada.** A evidência local demonstra
a decisão incorreta da auditoria; a interpretação das construções vem das
fontes primárias abaixo.

CSS Syntax Level 3 resolve escapes ao
[formar um identificador](https://www.w3.org/TR/css-syntax-3/#consume-ident-sequence)
antes de [reconhecer a função `url()`](https://www.w3.org/TR/css-syntax-3/#consume-ident-like-token).
Assim, a grafia escapada não elimina a referência. CSS Images Level 4, na
[versão de 30/09/2025](https://www.w3.org/TR/2025/WD-css-images-4-20250930/),
define em §2.4 os argumentos de string de `image-set()` como URLs; o Apêndice A
define `-webkit-image-set()` como alias de análise com os mesmos argumentos.
Esses casos pertencem à restrição de CSS ativo já documentada em
[F1-27](../SITES-F1-27-OFFLINE-PREVIEW-AUDIT.md).

## Correção delimitada

A alteração atua somente no conteúdo do único bloco `style` aceito pela
auditoria:

- Recusa barras invertidas, pois o dialeto fixo do renderer não utiliza
  escapes CSS. Isso cobre escapes hexadecimais e simples sem introduzir um
  interpretador ou normalizador de CSS.
- Recusa `image-set()` e `-webkit-image-set()`, sem distinguir maiúsculas e
  minúsculas, juntamente com as construções já bloqueadas.

Texto escapado dos briefings e o valor canônico da meta description continuam
aceitando barras invertidas e essas palavras. A entrada positiva dos testes
contém ambas as funções, escapes aparentes e um caminho textual com barra;
ela percorre os cinco campos dos dois produtos.

O renderer, seus vetores, o HTML persistido e seus hashes não foram alterados.
A auditoria apenas devolve problemas: não reserializa o documento, não
normaliza o conteúdo nem substitui os bytes usados para digest ou pacote.
Também não muda SQL, permissões, briefing, autoridade comercial ou publicação.

Os consumidores existentes continuam usando a mesma auditoria: o
[pacote sintético](../../src/Production/SyntheticReleaseBundle.php), o
[registro privado](../../src/Publication/PrivateReleaseRegistration.php) e a
[preparação de release](../../src/Publication/SiteReleasePreparation.php).
A política permanece específica do dialeto inerte; esta correção não a
transforma em sanitização genérica de HTML/CSS ou em aprovação de produção.

## Evidência local confirmada

| Verificação | Resultado observado |
| --- | --- |
| [F27 offline](../../tests/f27-offline-preview-audit.php) | **152 checks PASS**: 115 anteriores e 37 novos |
| [Registro offline](../../tests/release-registration-offline.php) | **279 asserções PASS**: 271 anteriores e oito novas |
| Suíte geral Laravel, sem cache de resultados | **296 testes / 4705 asserções / 27 skips PASS** |
| Laboratório sintético de domínio | **32 checks PASS** |
| Lint dos três arquivos PHP alterados e verificação do diff | **PASS** |

F27 cobre hex curto e de seis dígitos, presença ou ausência de espaço
terminador, maiúsculas e escapes simples de `url`/`@import`, além das duas
funções de imagem. Os pacotes modificados recebem digest recalculado e ainda
são recusados. Os testes de registro recalculam tamanho, digest e compromisso
para cada caso novo e exigem recusa em `plan` e `record`, com **zero chamadas à
PDO**. Não há sucesso SQL simulado nessas provas.

As duas suítes focadas passaram após a última ampliação. A suíte geral e o
laboratório de domínio também foram executados localmente pelo responsável
pela entrega. **O CI desta candidata ainda está pendente.** Não foi executado
PostgreSQL ou navegador local para esta correção.
