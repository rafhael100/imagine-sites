# F1-49 — Teste PHP de compatibilidade real entre dois repositórios

Este teste **executa as classes PHP dos dois repositórios** em um mesmo processo CLI. O emissor Sites `SyntheticReviewHandoffEnvelope` em F1-45/46 assina uma proposta; o consumidor Portal `SyntheticSitesReviewHandoffConsumer` candidato [Portal PR #161](https://github.com/rafhael100/imagine-portal/pull/161) valida independentemente os bytes HMAC e a estrutura. Esta é a direção Sites→Portal, complementar ao CreateProject Portal→Sites do F1-23.

O workflow `.github/workflows/sites-f1-49-portal-crossrepo.yml` faz checkout somente leitura do repositório Portal **fixado no SHA `2f5cd9ed77340f5d4ebc8056d029a1a79c53cc11`**, sem credenciais adicionais. Com PHP 8.3/8.4, ensaia Onepage e Landing Page com arquivos HTML gerados pelo próprio Sites, digest exato e proposta não publicável.

Negativas: consumidor default DENY sem snapshot atual confiável; replay das duas pontas; revogação antes de consumir nonce; revisão mais recente no Portal difere da proposta recebida; release diferente; tenant adulterado. Somente fontes de autoridade e nonces **fictícios** injetados por fixture permitem sucesso.

**Não é uma integração de serviços em execução**: as classes rodam localmente no CI, sem HTTP ou identidade confiável. O Portal ainda não possui um adaptador oficial conectado ao contrato, estado de cliente, autenticação, revogação verdadeira e prova durável por recurso. Não há aprovação, publicação, cobrança, CRM ou entrega. Se o código candidato Portal for alterado, a referência SHA exige atualização coordenada e nova revisão; não usar automaticamente a branch principal.

P0-DOC03, RF-PEND, RV-CT, AppSec/LGPD e gates OP01/OP03/OP05 permanecem abertos. VPS Leaseweb, Cloudflare, `main`, dados de clientes e infra de produção intocados.
