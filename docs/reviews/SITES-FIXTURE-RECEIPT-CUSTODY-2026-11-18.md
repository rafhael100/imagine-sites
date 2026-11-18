# Imagine Sites — custódia dos recibos dos auxiliares de CI

## Problema corrigido

Os auxiliares de preparação de pacote e inspeção já validavam as identidades dos recursos antes da limpeza. A leitura de senha e CA também usava descritores privados desde a PR #499. Os próprios recibos, porém, ainda eram abertos novamente pelo nome depois das verificações.

No negativo `verify-mismatch`, o helper alterava o recibo, executava um subprocesso e o restaurava em `finally` por pathname. Como o laboratório e o recibo pertencem ao usuário da fixture, uma substituição nesse intervalo poderia fazer a restauração privilegiada atingir outro alvo. A leitura segura das credenciais não cobria essas novas aberturas do recibo.

Esta correção fica na branch `fix/sites-ci-receipt-custody-2026-11-18`, sobre a PR #501 / `889bf55bde70e1ba617cb4fcde89e2c284c742e3`. O recorte contém os dois helpers antigos:

- `tests/fixtures/release-registry/cli-paths.py`;
- `tests/fixtures/publication-inspection/cli-paths.py`.

## Comportamento implementado

O helper mantém aberto o descritor do laboratório durante a operação. Recibos são lidos em relação a esse descritor, com validação de arquivo regular privado, owner, modo, número de links, tamanho e identidade antes e depois da leitura. Uma preparação nova cria o recibo de forma exclusiva, sem seguir links.

O negativo abre o recibo uma vez para leitura e escrita, compara sua identidade com a leitura validada e conserva esse descritor durante a alteração, o subprocesso e a restauração. O bloco `finally` restaura os bytes do arquivo originalmente aberto. A verificação final recusa substituição do recibo ou do laboratório; ela não usa o novo pathname como destino da restauração.

A origem de senha e CA usa o mesmo descritor do laboratório. A função administrativa de entrada mantém as exigências de root, ambiente descartável de GitHub Actions e opt-ins. A função interna importável permite testar a custódia em diretórios temporários, sem executar essa entrada administrativa.

## Limites preservados

Os caminhos fixos, owners, modos, mensagens e ordem de limpeza continuam iguais. Package mantém seu recibo de arquivos, diretórios privados e somente dos pais que ele próprio criou. Pais que já existiam não passam a ser exigidos no recibo nem são apropriados pela fixture. Inspection continua responsável apenas por seus três arquivos.

A limpeza conserva a validação de todas as identidades antes da primeira remoção. O tratamento dos pais opcionais continua sem remoção recursiva. O runner segue reconciliation → binding → editorial → registration → inspection → package.

Esta entrega não altera APIs PHP/SQL, schema, grants, conteúdo de sites ou CLIs do produto. Ela corrige aberturas específicas dos recibos e não afirma eliminar toda condição de corrida de qualquer operação de filesystem.

## Verificação

A regressão `tests/fixture-receipt-custody.py` reproduz a substituição do recibo durante o subprocesso do negativo, usando somente diretórios temporários próprios. Comprovou que o alvo substituto permanece intacto, o arquivo original recupera seus bytes pelo descritor e o helper recusa concluir normalmente. O caminho estável e os limites de propriedade também são exercitados. A regressão existente `tests/fixture-private-material.py` continua cobrindo a leitura das fontes privadas.

**Validação local aprovada: 14 testes de custódia dos recibos e 18 de material privado.** Os sete cenários por helper incluem fluxo estável, substituição por symlink e por arquivo regular durante o subprocesso, symlink anterior à abertura, modo indevido, troca do laboratório durante o subprocesso e laboratório substituído antes da entrada. A decisão de recusa de cleanup é exercitada pela função real, dentro do callback controlado que representa a fronteira do subprocesso. Todos os destinos ficam em diretórios temporários próprios.

Sintaxe Python, YAML, pins, sete flags, blocos shell, whitespace e revisão independente aprovados. O safety guard passou em uma cópia apenas das fontes, mantendo os dez gates `BLOCKED`. Nenhum helper administrativo foi executado localmente em `/run`, e não foi iniciado PostgreSQL local.

O workflow `.github/workflows/sites-fixture-receipt-custody.yml` fixa draft, repositório, branch e SHA da base, com PHP 8.3/8.4, sete opt-ins e o mesmo laboratório PostgreSQL descartável. Executa a regressão nova e os ensaios reais de preparação, negativos e limpeza de todos os candidatos. **O CI desta correção permanece pendente**; os 712 checks de registry da base são uma expectativa de regressão, não um resultado antecipado da nova HEAD.

## Base validada

A PR #501 está validada em `889bf55bde70e1ba617cb4fcde89e2c284c742e3`, com **17/17 check-runs SUCCESS** na conferência final. O [run 44231736687](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687) passou nos jobs [PHP 8.3 / 134558158980](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687/job/134558158980) e [PHP 8.4 / 134558159205](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687/job/134558159205), com **49 verificações de reconciliação, 712 registry, 386 workspace e PHPUnit 134 testes/1186 assertions/27 skips**. Os seis negativos de recibo, a limpeza completa e os clusters inativos foram confirmados.

O [relatório de reconciliação](SITES-PUBLICATION-RECONCILIATION-2026-11-18.md) registra essa evidência. Os dez gates continuam `BLOCKED`, e os requisitos independentes da [integração central](SITES-NEXT-INTEGRATION-2026-11-18.md) permanecem vigentes.
