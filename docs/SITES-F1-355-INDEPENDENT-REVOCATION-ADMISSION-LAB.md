# F1-355 — admissão sintética Portal↔Sites com revogação fora do domínio restaurado

**Estado:** protótipo PHP puro e testes offline, para revisão P0 da issue #33 e recuperação da #34. NÃO autoriza HTTP real, credenciais, banco, merge em main ou publicação.

## O problema que a entrega enfrenta

O [teste F1-354 no PR #442](https://github.com/rafhael100/imagine-sites/pull/442) demonstra a limitação de uma tabela de revogação guardada no próprio banco que se pretende restaurar: um restore antigo feito com privilégios de proprietário pode restaurar uma associação antes revogada. Triggers locais não garantem revogação após restauração integral.

Um comando assinado também não substitui uma consulta à autoridade atual: o comando pode ter sido autorizado na emissão e já estar revogado no momento da admissão.

## Modelo candidato (sem infraestrutura)

- `SyntheticAuthoritySnapshot` representa **somente** prova fictícia de revisão atual por `tenantRef + contractRef`, estado ativo/revogado e observação/validade com janela máxima de 30s.
- `SyntheticExternalRevocationFence::assertAdmissible` recebe um `SiteProjectCommand` autenticado **no laboratório** por `SyntheticSignedCommand`, um snapshot simulado do Portal e outro simulado de uma fonte de revogação **fora do domínio de restauração do Sites/Portal**.
- Exige presença de ambas as fontes; vínculo exato de empresa e contrato; snapshots não futuros nem vencidos; status ativo em ambas; revisão do comando igual às revisões de ambas as fontes. Divergência, indisponibilidade, restauração antiga ou revogação retornam **DENY**.
- O comando autenticado e o `ReplayStore` existente fazem parte do teste integrado, mas **não** fornecem IAM, consulta de contratos, replay durável ou assinatura verificada de snapshots externos.

## Provas em CI

`php tests/f355-independent-revocation-gate.php` roda no workflow PHP 8.3 e 8.4; inclui caminho positivo sintético, replay negativo, fontes ausentes, revisões antigas/novas divergentes, revogações, troca de empresa/contrato, expiração, datas futuras e campos inválidos. Todos os dados são inventados e as chaves são explicitamente de teste.

## Gate ainda não satisfeito

É necessária uma implementação externa auditável da fonte de revogação com armazenamento independente, sem restaurar seu epoch junto com os dados do Sites; assinaturas/freshness e disponibilidade de serviço comprovadas, mTLS/alternativa homologada, nonce durável, gestão de chaves no vault, contratos/titularidade consultados no Portal e testes de recuperação real. Se qualquer fonte estiver indisponível, nenhuma **nova operação** deve ser admitida; sites estáticos já publicados não devem ser derrubados automaticamente por falha do Portal.

**NO-GO:** testes offline aprovados jamais significam autorização de produção ou prontidão dos 10 gates de `config/operational-readiness.json`. Esta entrega não ativa API, VPS, Cloudflare, DNS, clientes, PII ou custos.


## F1-356 — atestações com origem e assinatura distintas

SyntheticAuthorityAttestation emite e verifica envelopes JSON canônicos HMAC-SHA256 com issuer, tenantRef, contractRef, revision, active, observedAt, expiresAt e mac, usando **chaves de laboratório distintas** para imagine-portal-authority e imagine-independent-revocation. SyntheticSignedAuthorityAdmission rejeita reutilização da mesma chave entre comando, Portal e fonte independente, confere origem esperada, assinatura, expiração e chama o fence de revogação.

tests/f356-signed-authority-admission.php cobre alteração de bits, mudança de contrato e tenant, revogação assinada, provas de outra origem, troca de credenciais, ordem/quantidade dos campos, revisão fora de época, repetição de nonce e provas vencidas. **PHP 8.3 e 8.4 aprovados:** [run #43490332232](https://github.com/rafhael100/imagine-sites/actions/runs/43490332232).

O MAC não demonstra que os registros vieram de serviços reais, que as chaves estão em vault, que a fonte de revogação está fora do domínio de backup ou que a informação foi reconsultada em cada transação. Provas possuem janela curta fictícia e não são autorização operacional.

## F1-357 — pedido assinado até revisão privada (fluxo PHP puro)

SyntheticSitesOrderIngress conecta comando, duas atestações, nonce de replay e SyntheticIdempotencyLedger **somente em memória** antes de criar um SyntheticProductionWorkflow em estado queued. Uma idempotencyKey repetida com a mesma semântica não cria outra fila; reuso conflitante é recusado. O teste percorre queued → drafting → internal_review → awaiting_portal_acceptance, liga a revisão ao digest SHA-256 exato e exige officialClientAcceptance=false/publishable=false.

tests/f357-synthetic-order-to-review.php verifica Onepage e Landing Page, tentativas duplicadas, revisão de contrato alterada, pedido revogado, siteRef inválido e alçada de revisão. **PHP 8.3/8.4 aprovados:** [run #43490783387](https://github.com/rafhael100/imagine-sites/actions/runs/43490783387).

Isso ainda **não** é checkout, comprovação de pagamento, fila durável, n8n, aprovação oficial, serviço IAM real, geração por IA ou publicação.

## F1-358 — comando Laravel para pacote privado de demonstração integrada

A aplicação Laravel recebe o comando **somente em testes CI**: sites:authenticated-order-fixture {onepage|landing_page}. O comando usa dados e chaves visivelmente fictícios, passa pelo ingress F1-357, monta rascunho offline com o renderer do projeto, revisa internamente e grava somente em storage/app/private/sites-f358-{product} os arquivos de preview inerte, manifesto e proposta de revisão. Bloqueia fora de CLI, APP_ENV=testing, GITHUB_ACTIONS=true e SITES_AUTHENTICATED_ORDER_LAB=1. Também recusa output existente, produto inválido e caminhos symlink.

O workflow .github/workflows/sites-f1-37-laravel-review-fixture.yml valida negações sem flags, executa os dois produtos e testa arquivos/digests e não publicabilidade, seguido de SyntheticAuthenticatedOrderFixtureCommandTest. **Nenhuma rota HTTP é registrada.** A flag GITHUB_ACTIONS=true não é prova criptográfica de procedência; é uma trava adicional sobre um laboratório isolado, jamais aceitável para autorizar produção.

**Saída esperada:** prévia privada não indexável, officialClientAcceptance=false, publishable=false, nenhuma entrega, domínio ou endpoint público. CI específica da F1-358 deve ser conferida no commit que a incluiu.

## Passos reais ainda bloqueados para homologação

Para progredir além de CLI: fonte externa de revogação verdadeiramente independente e com restauração testada; autenticação S2S usando segredos de homologação do vault; contratos, pagamento e titularidade conferidos no Portal oficial; persistência com outbox/replay/idempotência duráveis e DB isolado; servidor de homologação, DNS e Cloudflare autorizados; aprovação expressa do cliente para release+digest, publicação independente e confirmação de entrega. Tudo exige revisão DBA/AppSec, LGPD e gates de release. Não usar este código como implementação produtiva.
