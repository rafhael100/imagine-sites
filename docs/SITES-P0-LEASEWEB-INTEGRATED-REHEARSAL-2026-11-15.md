# Homologação integrada Sites ↔ Portal — 15/11/2026

## Fontes verificadas antes da alteração

Sites PR478 `69a71c074d46af2a006e9ceead01c07692a53a07`: todos os checks recuperados aprovados (inclui PR477 e seu verificador duplo). Portal PR759 `975bb83a1937906b5b0bf33036410f99a553394f`: 10 checks aprovados. PRs administrativas mais novas do Portal são frentes distintas; não substituem o candidato de integração Sites. Nenhuma branch paralela foi sobrescrita.

A PR477 ainda fixa Portal PR756, anterior à integração do resolvedor privado. A nova workflow adiciona prova explícita do código original PR759 com o verificador duplo Sites F396, a matriz F397 e o transporte HTTPS mTLS F395. O gerador `f399-generate-current-portal-acceptance.php` reutiliza o fixture da PR475 (SHA2ecd7135a8cf71f130a574c4ef50eac12f9b6987), usando o resolvedor original do Portal. Todas as fontes de negócio, consentimento e identidade nesse ensaio continuam **sintéticas**. Certificado de teste não é IAM operacional.

## Diagnóstico real na VPS

Não havia chave/agent/config SSH neste executor. Foi reutilizado o console Leaseweb já autenticado, sem pedir ou ler credenciais. Confirmados: Ubuntu24.04.5, Docker Compose2.40.3, aproximadamente 23GiB RAM / 22GiB disponíveis e 274GiB livres no filesystem raiz. Contêineres existentes foram apenas observados; bancos existentes estavam saudáveis no inventário Docker, sem portas PostgreSQL publicadas nesse inventário.

Foi criada somente uma cópia de fonte independente para diagnóstico; nenhuma aplicação, rede, configuração ou banco existente foi alterado. Executado o comando solicitado `python3 ops/homologation/leaseweb-readonly-preflight.py --probe-host` na SHA exata PR478: **status=blocked**, com estrutura Compose/Ubuntu/Docker observados e todos os nove gates operacionais fechados. Isso é diagnóstico de host, não homologação do fluxo.

**Problema comprovado:** porta candidata `127.0.0.1:18081` já ocupada por um gateway CRM existente. O preflight original só validava a estrutura do Compose, não a ocupação efetiva. Novo `leaseweb-host-impact.py` consulta apenas metadados de binds Docker e listeners do kernel; falha fechada se ausentes/inválidos, detecta conflito externo ao Docker e binds públicos de DB. Não lê `.env`, variáveis/commands/labels Docker, chaves, conteúdo de backups ou tabelas. Metadados de backup são limitados e não provam restore.

A porta NÃO foi alterada, liberada ou considerada reservada. Próxima implantação precisará de projeto, rede e porta próprios, revisados contra inventário vigente.

## Fluxo e estado real

| Etapa | Evidência técnica | Operacional pendente |
| --- | --- | --- |
| Contratar Site Onepage / Landing | Contratos e pedidos originais do Portal exercitados em CI | IAM humano e ledger comercial ligados ao fluxo |
| Confirmar Asaas | Regras/casos de checkout e confirmação sintética existentes | Credenciais Sandbox, webhook verificado e conciliação oficial |
| Criar projeto | Admissão HTTP+PG18/RLS demonstrada em CI anterior | Bancos Sites/Portal próprios provisionados e revisados na VPS |
| Produzir prévia | Renderer e conteúdo estruturado de laboratório | Produção real pela Imagine/IA autorizada, material do cliente |
| Revisar editorial | Revisões CAS e editor/revisor distintos em SQL candidato | Identidades editoriais e trilha operacional |
| Aceitar no Portal | Emissor Ed25519 e resolvedor privado originais testados | MFA/consentimento real no documento e versão apresentados |
| Validar versão | Vínculo tenant/pedido/contrato/site/release/digest/revisão | Fonte autoritativa corrente e rechecagem na execução |
| Consultar revogação | Contratos/testes independentes e negativas | Testemunha independente, durável, restaurável e operacional |
| Publicar / rollback | Política default-deny e manifesto de release | Publisher, observação independente, domínio/TLS, autorização e rollback ensaiado |

## Ensaio privado opcional e limitado

`run-private-domain-rehearsal.py --php-image imagine-crm-private-journey:<SHA>` pode executar somente `tests/run.php`, com uma imagem PHP **já existente**, tag SHA exata, `--pull=never`, usuário33, filesystem/source read-only, rede none, sem portas/socket/segredos e limites CPU/memória/PIDs. O código original de domínio não requer simular ambiente CI. Nenhuma flag `GITHUB_ACTIONS` é inventada na VPS; ensaios mTLS/PSP guardados para CI permanecem no GitHub.

O executor não usa o banco, volumes ou a aplicação CRM; só reaproveita o binário PHP da imagem local. Compara IDs dos contêineres existentes antes/depois e não retém contêiner. Isso não comprova contrato, gateway, consentimento, RLS, PostgreSQL18, TLS S2S, restore ou publicação na VPS. Resultado real da execução será registrado após conferência; nunca presumido.

## Domínio, TLS, recuperação e pendências

Nenhuma entrada DNS/Cloudflare, listener operacional ou rota pública foi criada. Certificados e segredos operacionais não foram solicitados pelo chat. TLS público preexistente de outros projetos não comprova mTLS Sites↔Portal. Backup de outro produto não homologa backup do Sites: exigir retenção externa, proteção de chave, restore em banco separado, preservação de revogação/replay e revisão independente.

A homologação funcional completa permanece bloqueada por: conflito de porta, banco/roles Sites PG18 não provisionados, origem oficial de pagamento/aceite/revogação, S2S autenticado/vault, consentimento humano, backup externo+restore, publisher/rollback e gates AppSec/LGPD. Nenhum resultado verde libera esses requisitos.

DRAFT / NO-MERGE / NO-PRODUCTION-DEPLOY / NO-CHARGE / NO-PUBLISH. Issue38 permanece aberta.
