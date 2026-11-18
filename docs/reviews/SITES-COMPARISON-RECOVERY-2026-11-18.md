# Imagine Sites — recuperação do formulário de comparação

## Problema

O formulário privado permite informar duas versões para comparar. Os limites individuais dos inputs numéricos não impedem versões iguais ou em ordem invertida. Antes desta correção, o servidor recusava esse par com uma resposta de texto 422 e retirava da tela o formulário, o histórico e o briefing atual. O usuário precisava voltar para corrigir a escolha.

A branch `fix/sites-preview-comparison-recovery-2026-11-18` parte da PR #502 / `5234e3ca36aeadbb363435ebef1f23a26ea9852e`. O objetivo é conservar a recusa 422 e apresentar o erro no workspace autorizado, junto ao formulário de comparação, para permitir a correção ali mesmo.

## Escopo

Erros recuperáveis de seleção não são tratados como uma comparação válida. A navegação base continua sendo validada de forma estrita. A tela preserva os valores numéricos seguros enviados, os filtros e busca da fila, a página do histórico e o briefing da versão atual. Um campo enviado vazio permanece vazio; não é substituído silenciosamente pela revisão atual.

O servidor não consulta um par inválido nem grava uma nova versão, parecer ou decisão editorial. Os seletores de comparação pertencem ao formulário GET e continuam fora dos formulários de edição e revisão, suas URLs POST e a sessão.

Entradas estruturais inválidas permanecem recusadas. Um par válido que aponta para versão inexistente continua retornando 404. Falhas de autorização ou de backend não são convertidas em erro de seleção nem expõem conteúdo do projeto. Nenhum schema, contrato Portal, grant ou dado operacional é alterado.

## Verificação

A implementação acrescenta `PreviewComparisonInput` e usa a recuperação somente no GET do projeto sem ação adicional. `PreviewNavigation` permanece intacto e estrito. O DTO aceita apenas o erro de ordem em revisões decimais canônicas de 1 a 2147483647 ou seleção parcial/vazia. A navegação base é validada novamente sem os seletores antes de carregar o workspace. A view associa o erro aos dois campos e oferece “Limpar comparação”, inclusive para projetos com uma versão.

**Validação local aprovada:** 37 testes/466 assertions no recorte de DTO, navegação e views; suíte completa com **141 testes/1310 assertions/27 skips**. Lint, sintaxe JavaScript, YAML, pins, blocos shell, whitespace e revisões independentes do produto e da fixture passaram. O safety guard foi aprovado em cópia de fontes, com os dez gates `BLOCKED`.

A fixture executou **39 verificações HTTP novas, preservando as 273 existentes, total de 312 aprovadas**. Exercita a correção pelo formulário real sem JavaScript, filtros/busca/cursor, duas direções de paginação do histórico e CAS/head atual. Um POST real com headline de 181 caracteres chegou à validação específica do briefing, sem seletores de comparação e sem criar revisão. Snapshots de todas as linhas das cinco tabelas `sites_preview` verificam que a recuperação e esse POST recusado não alteram estado. Arrays, contexto inválido, versão ausente, outra empresa e revogação também são cobertos. Três capturas de erro desktop/mobile e resultado corrigido foram conferidas: campos e mensagem legíveis, links de correção visíveis e ausência de overflow.

O workflow `.github/workflows/sites-comparison-recovery.yml` fixa draft, repositório, branch e SHA da base. Executa PHPUnit e o contrato PostgreSQL do workspace em PHP 8.3/8.4, além da jornada HTTP com Laravel/FPM, Nginx e PostgreSQL TLS descartáveis. O escopo de regressão segue o fluxo alterado; a cadeia de registro de releases já tem evidência própria na base.

## Evidência concluída da PR #503

HEAD validada: `48d190f22ef9000dc52db55cc6b56510df6f7624`; **19/19 check-runs SUCCESS**. O [run 44234103268](https://github.com/rafhael100/imagine-sites/actions/runs/44234103268) passou nos jobs [PHP 8.3 / 134565952833](https://github.com/rafhael100/imagine-sites/actions/runs/44234103268/job/134565952833), [PHP 8.4 / 134565952590](https://github.com/rafhael100/imagine-sites/actions/runs/44234103268/job/134565952590) e [HTTP / 134565952834](https://github.com/rafhael100/imagine-sites/actions/runs/44234103268/job/134565952834).

As duas versões de PHP confirmaram **386 verificações SQL do workspace e PHPUnit 141 testes/1310 assertions/27 skips**. O navegador confirmou **39 checks novos e 312 totais**, incluindo recuperação real, POST recusado, snapshots inalterados, empresa cruzada e revogação. Clusters SQL, contêineres e redes descartáveis foram encerrados/removidos.

O artifact `14396079009` foi baixado e conferido pelo SHA-256 `244c00f1953794e539bd56b957d071ab667bf6604c756851404cd09b03cf98e5`: 20 arquivos, incluindo as três capturas novas e `result.json` com 312 checks. A inspeção visual aprovou erro desktop/mobile e comparação corrigida. Nenhum Docker ou PostgreSQL local foi iniciado.

## Base e limites

A correção dos recibos está isolada na PR #502. Seu [relatório](SITES-FIXTURE-RECEIPT-CUSTODY-2026-11-18.md) registra a custódia por descritores e a regressão determinística. A base foi validada com **17/17 check-runs SUCCESS** no [run 44232778190](https://github.com/rafhael100/imagine-sites/actions/runs/44232778190): PHP 8.3/8.4, 14 testes de recibo, 18 de material privado, 712 registry, 386 workspace e PHPUnit 134/1186/27. Os seis negativos de recibo, a limpeza completa e os clusters inativos foram confirmados. Essa evidência pertence à base; não antecipa a validação da recuperação de comparação.

O workspace continua uma demonstração privada. Revisão editorial não representa aceite do cliente nem autorização de publicação. Os dez gates permanecem `BLOCKED`, e staging e dados reais permanecem preservados.
