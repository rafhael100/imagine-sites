# F1-128 — Pré-verificação HMAC pura, sem armazenamento provisório de nonces

## Problema

A F1-125 precisava validar a assinatura HMAC v3 **antes** de consultar a autorização comercial por recurso. Para isso, utilizava `LabPreflightNoPersistReplayStore`, que implementava `ReplayStore` e retornava `true` sem persistir o nonce. A F1-127 impediu que esse adaptador fosse injetado como armazenamento **definitivo** de replay. Mesmo assim, manter um adaptador que aceita todos os nonces ampliava o risco de uso indevido e misturava duas responsabilidades diferentes: verificar assinaturas e consumir nonces.

## Mudança

Os validadores sintéticos `SyntheticSignedCommand`, `SyntheticVersionedSignedCommand` e `SyntheticCommercialEpochSignedCommand` agora expõem dois caminhos distintos:

- `verifySignature(...)`: verifica formato exato e ordem de campos, MAC, emissor/audiência, validade temporal, payload/escopo e épocas autenticadas, **sem receber nem instanciar um ReplayStore**. Retorna um comando autenticado *apenas para consulta interna*, sem autorizar operação, projeto ou publicação.
- `verify(..., ?ReplayStore $store)`: primeiro executa a mesma validação rigorosa; depois chama `rememberOnce` com o nonce assinado. Sem armazenamento passado, a implementação permanece `DenyingReplayStore`. O modo de admissão v3 no `LabSignedProjectIntake` segue exigindo `LabPostgresReplayStore` restrito e durável no laboratório.

A pré-verificação do intake v3 utiliza `SyntheticCommercialEpochSignedCommand::verifySignature` para obter um comando já autenticado antes da consulta à fonte comercial. Após confirmar a revisão comercial independente, só o **segundo passo definitivo**, `verify(..., $this->replay)`, consome o nonce no PostgreSQL.

A classe antiga `LabPreflightNoPersistReplayStore` foi desativada: seu método `rememberOnce` agora **sempre devolve falso**, inclusive com flags de laboratório habilitadas. Ela fica no repositório exclusivamente como compatibilidade temporária e evidência do bloqueio F1-127, não como etapa funcional.

## Evidências verificáveis

A matriz `sites-f1-94-project-admission.yml` executa `tests/f111-pg-durable-signed-intake.php` em PHP 8.3 e 8.4 com duas bases efêmeras PostgreSQL 18 independentes. Os novos cenários F1-128 comprovam:

1. Duas pré-verificações do mesmo envelope v3 já consumido continuam validando a **assinatura**, mas a admissão real recusa o replay.
2. As verificações de assinatura v1/v2 preservam a validação semântica do comando.
3. O antigo adaptador não persistente **nega incondicionalmente**.
4. Repetir a pré-verificação de um nonce novo não consulta a fonte comercial nem consome nonce. O intake v3 pode consumi-lo uma vez no banco durável; outra conexão PostgreSQL recebe DENY.
5. Alterações do HMAC v3 são negadas mesmo sem acesso ao armazenamento de nonce.

Nenhuma migração, mudança de contrato de envelope, API HTTP, integração com chaves reais ou mudança operacional é introduzida.

## Limites de segurança

A validação criptográfica **sem replay** não constitui autorização de projeto e não pode substituir `verify` com armazenamento durável nem a consulta comercial atual. A prova utiliza chaves, fontes e pedidos fictícios e bancos PostgreSQL descartáveis. A revisão comercial pode ficar obsoleta e a GUC SQL de tenant não equivale a identidade IAM se houver acesso SQL arbitrário. O serviço operacional ainda necessita IAM real Portal, canal mutuamente autenticado, chaves sob vault, revogação monotônica e auditoria independente.

Os PRs continuam em draft/empilhados. Não houve merge em dev/main, deploy, uso da VPS, DNS, Cloudflare, dados pessoais ou despesas. **10/10 gates operacionais permanecem BLOCKED.**
