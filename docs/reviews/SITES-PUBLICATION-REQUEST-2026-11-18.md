# Imagine Sites — solicitação privada do diário técnico

## Fluxo atendido

O pipeline existente já registra o artefato, exige revisão editorial independente, associa a release e prepara seu pacote técnico. O diário possui uma API persistente de solicitação idempotente, mas faltava uma entrada privada reutilizável que preservasse a referência retornada para consulta posterior.

A branch `feature/sites-publication-request-2026-11-18` parte da PR #504 / `f7cef0082644eee3ef74815a7130f62dbb5e3b8b`. A CLI candidata `ops/publication-candidate/request.php` planeja e solicita uma operação por meio de `PgPublicationJournal::request`. A referência é gravada em um arquivo privado novo e pode compor o manifesto do inspetor existente. O recorte usa a API SQL atual, sem mudar o schema ou suas regras de idempotência.

## Contrato e confirmação

O manifesto `SITES_PUBLICATION_REQUEST_V1` tem exatamente oito campos: `schema`, `target`, `siteRef`, `releaseRef`, `expectedBindingRevision`, `portalAcceptanceRef`, `idempotencyKey` e `receiptRef`. O alvo conserva os sete seletores de conexão/TLS já usados pelos demais candidatos. O parser recusa campos duplicados, extras e tipos incorretos. A revisão esperada identifica a associação da release; não é a revisão dos eventos do diário.

`plan` produz seis campos técnicos e não cria recibo, consulta a operação ou comprova sua elegibilidade comercial. O hash vincula todos os seletores, incluindo o nome restrito do recibo. `apply` exige esse hash e a confirmação literal `--authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED`. O operador usa um principal cuja única capacidade executável no diário é `request_operation`, sem grant option, acesso direto às tabelas ou outras APIs de core/journal.

A resposta pública de `apply` tem 16 campos: os seis do plano, com schema de resultado e status `recorded`, mais estado técnico, revisão, número da tentativa, revisões editorial/associação, digests do artefato/recibo e timestamps. Ela mantém `canPublish=false` e `publicationVerified=false`. `recorded` descreve o registro retornado e não afirma criação de evento novo. Referências comerciais, operação, idempotência, caminhos e tokens não são impressos.

O recibo privado tem somente `schema=SITES_PUBLICATION_REQUEST_RECEIPT_V1`, `planSha256` e `operationRef`. Ele é um ponteiro para inspeção posterior, sem uma cópia do estado mutável do diário.

## Idempotência e autoridade corrente

Uma solicitação nova depende da associação corrente, artefato imutável, revisão editorial aprovada e identidades válidas, conforme a API existente. `portalAcceptanceRef` é uma referência declarada com validação de formato; a solicitação não verifica consentimento do cliente ou pagamento.

O replay da mesma chave, empresa e seletores retorna a mesma operação com seu evento mais recente. Pode ser `requested`, `dispatched`, `acknowledged` ou `inconclusive`. Um escritor atualmente válido da mesma empresa pode recuperar esse registro, inclusive após mudança de ator/epoch ou revogação da associação histórica. A validação do ator corrente continua obrigatória. O wrapper não exige que o solicitante seja o autor histórico e não confunde revisão da associação com revisão do diário.

Cada aplicação faz uma única chamada à API de solicitação. Não há retry ou ajuste automático de seletor. Um conflito ou erro pode exigir inspeção privada antes da próxima decisão.

## Recibo e falhas entre recursos

Os três inputs usam mounts fixos: `/run/secrets/publication_request`, `/run/secrets/sites_publication_request_writer` e `/run/secrets/sites_publication_request_ca`. O destino é `/var/lib/imagine-sites/publication-requests/<receiptRef>.json`, sob diretório canônico pertencente ao usuário efetivo em modo 0700. O nome aceita apenas o subconjunto ASCII limitado já usado para referências de pacotes.

Depois de validar o plano, a CLI reserva exclusivamente um arquivo vazio em modo 0600 e mantém seu descritor aberto. Confere novamente inputs, reserva e identidade antes de habilitar escrita na sessão e chamar o SQL. O recibo retornado é escrito pelo mesmo descritor, sincronizado e relido; a resposta pública só é emitida depois dessa verificação.

Não existe transação atômica entre PostgreSQL e filesystem. Uma falha pode deixar reserva vazia, arquivo parcial ou recibo completo, inclusive quando a operação já foi persistida. O código não remove, repara ou sobrescreve esse destino e não promete rollback. Uma recuperação exige apuração e um novo `receiptRef` explícito, com novo plano; a chave idempotente original pode preservar a identidade da operação. As conferências de path/ancestrais recusam trocas observadas e não constituem travessia atômica por `openat` nem proteção contra toda corrida do mesmo usuário/root.

## Verificação

**434 verificações offline do serviço e 207 da CLI/recibo aprovadas localmente.** A primeira suíte cobre parsing, limites, vinculação de cada seletor/alvo ao plano e recusas antes de conexão, sem fabricar sucesso SQL. A segunda inclui 85 checks de help/recusas com autoload bloqueado e 122 de I/O puro em diretórios temporários próprios. Ela comprova criação exclusiva, três campos exatos, gravação pelo mesmo inode, preservação em substituições de arquivo/diretório, recusa de links/modos indevidos e ausência de remoção automática. O lint dos novos arquivos e as revisões independentes do serviço e wrapper passaram.

O ensaio próprio do helper de CI passou em **seis casos**: limpeza completa de recibo e reserva vazia; divergência no último inode registrado; filho inesperado; symlink; hardlink; e modo indevido. Todos usam somente diretórios temporários próprios. Os negativos exigem recusa específica e preservação integral antes da primeira remoção. A lista de saída corresponde exatamente aos 16 nomes usados pela fixture. PHPUnit local passou com **141 testes/1310 assertions/27 skips**, e o safety guard manteve os dez gates `BLOCKED`.

A fixture PostgreSQL está implementada e prevê **81 verificações novas/797 registry**. Consome o HTML da geração local e percorre registro, revisão editorial, associação, solicitação e inspeção pelas CLIs existentes. Exercita os quatro estados de replay, mudança de ator/epoch, associação histórica revogada, empresa B comprovadamente elegível e escritor corrente inativo/revogado. Distingue recusa anterior à reserva de recusa SQL. Um bloqueio advisory observado dentro da chamada permite substituir somente a reserva própria antes do commit, provando depois a persistência de uma operação/um evento com falha do recibo e recuperação explícita em outro nome. Os cenários com tentativa usam dois sites distintos, preservando a unicidade permanente por empresa/site. **81/797 são previsões; CI desta HEAD ainda pendente.**

O workflow candidato fixa draft, repositório, branch e SHA da base, mantém PHP 8.3/8.4 e acrescenta o opt-in `SITES_PUBLICATION_REQUEST_CI`. O laboratório continua descartável e deve confirmar sua própria limpeza. Não há execução local de PostgreSQL nesta revisão.

A primeira execução, [run 44238802471](https://github.com/rafhael100/imagine-sites/actions/runs/44238802471), chegou ao replay histórico nos dois PHP e recusou corretamente uma reabertura solicitada pelo editor. A fixture foi corrigida para usar o revisor, conforme a regra SQL existente. O código do produto e suas permissões permaneceram iguais. Os sete auxiliares foram limpos e os clusters descartáveis ficaram inativos mesmo nessa falha; a nova execução completa permanece pendente.

## Base validada e limites

A PR #504 tem **17/17 check-runs SUCCESS** no [run 44236130507](https://github.com/rafhael100/imagine-sites/actions/runs/44236130507), com **716 registry, 386 workspace e PHPUnit 141 testes/1310 assertions/27 skips** em PHP 8.3/8.4. Os 137 checks de renderização, 87 da CLI, seis negativos reais de custódia, limpeza completa e clusters inativos foram confirmados. O [relatório da geração local](SITES-LOCAL-ARTIFACT-RENDERING-2026-11-18.md) registra os jobs e a cadeia consumidora.

Esta entrega conserva os limites do diário: solicitação técnica não autoriza envio, ACK não comprova publicação, e aceite/pagamento permanecem fontes independentes. Não acrescenta tentativa, ACK, lease token, provider ou dispatcher. O [contrato de integração central](SITES-NEXT-INTEGRATION-2026-11-18.md) continua separado. Staging, serviços, dados reais e os dez gates `BLOCKED` permanecem preservados.
