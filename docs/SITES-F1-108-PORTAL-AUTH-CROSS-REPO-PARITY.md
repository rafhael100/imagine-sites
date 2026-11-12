# F1-108 — Interoperabilidade do comando Portal com o novo contrato de autorização Sites

## Objetivo

Comprovar que o **comando realmente produzido pelas classes candidatas do Imagine Portal** (em uma revisão pública fixa) é aceito pela estrutura estrita `CurrentPortalProjectAuthorizationSnapshot` do Imagine Sites, sem presumir integração operacional ou concessão de autorização.

## Implementação e testes

A suíte existente `tests/f23-cross-repo.php` passa a envolver o `SitesCreateProjectEncoder` do repositório Portal (checkout na revisão pública fixa `bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`) em um registro candidato de autorização Sites com revisão, status e TTL de laboratório.

Para **onepage** e **landing_page**, a suíte:
- verifica que o comando original continua equivalente após envelope assinado sintético e verificação Sites, com deduplicação;
- codifica esse mesmo comando real no JSON compacto do novo contrato `sites.portal-project-authorization.v1` e exige `ProjectSnapshot::fromCanonicalJson(...)->isCurrentFor(...)`;
- altera a revisão contratual e exige que ela não herde a autorização ligada ao comando original;
- comprova que o gateway Portal por padrão continua negando mesmo com um registro de estrutura válida.

O workflow `.github/workflows/sites-f1-23-cross-repo-contract.yml` executa o teste em PHP 8.3/8.4 e passou a ser acionado também por mudanças nas classes relevantes do contrato Sites.

## Limites e pendências

O checkout Portal é uma **revisão pública fixada**, não as alterações atuais em branches de desenvolvimento. O envelope assinado é inteiramente sintético e não representa as credenciais reais, sessão, MFA, contrato jurídico ou emissão autenticada de autorização. O snapshot é montado pelo teste Sites, **não enviado por um serviço Portal real**. O teste não comprova compatibilidade com a próxima implementação operacional de IAM, política de autorização ou provedor de revogação.

Não há deploy, conexão de rede entre serviços, banco de produção, dados pessoais, merge, DNS, VPS ou custos novos. Os 10 gates de produção permanecem `BLOCKED`. É necessária revisão independente antes de integração.
