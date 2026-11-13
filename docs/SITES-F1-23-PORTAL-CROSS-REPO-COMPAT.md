# SITES-F1-23 — Paridade real do código candidato Portal ↔ Sites (em CI, sem API)

**Natureza:** duas implementações PHP de repositórios diferentes, exercitadas **em um mesmo processo CLI de laboratório** com contratos comerciais e chaves fictícias. Não é implantação, integração de rede nem homologação de identidade.

## O que é verificado

O CI confere o código **exato** do Portal PR #105 (HEAD `bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`) a partir do repositório público `rafhael100/imagine-portal`, em segundo checkout somente leitura, fixado pelo SHA. Não consulta a branch `main` do Portal e não instala dependências.

Instancia diretamente `SitesCreateProjectEncoder`, `SiteContractSnapshot` e `SyntheticSitesEnvelopeSigner` do Portal. Compara seu envelope HMAC com `SyntheticSignedCommand` e `SiteProjectCommand` do motor Sites, exercitando Onepage e Landing Page, ordem dos campos, digest/fingerprint, expiração, rejeição de adulteração, issuer desconhecido, operação sem contrato e replay.

A fonte `F23ContractSource` só retorna um pedido inventado e **não** comprova existência de cliente, contrato elegível ou pagamento. O teste consome nonce em memória e usa chave pública de fixture, não constitui proteção real a replay multiworker. Nenhuma chamada HTTP, rede de cliente, banco ou Cloudflare é realizada (além do checkout público GitHub necessário para CI).

## Critério de evolução

- O resultado PASS é evidência de **compatibilidade binária/canônica das duas classes candidatas fixadas**, não da API produtiva, autenticação, concessão de direitos ou aprovação do cliente.
- Caso o PR do Portal seja alterado, a revisão do SHA pinado e a atualização coordenada da matriz de contratos devem ser realizadas em PR próprio, com CI de ambos os lados.
- Se o Portal tornar-se privado, não ampliar permissões nem adicionar secrets automaticamente. Registrar bloqueio e decidir mecanismo seguro de distribuição do contrato.
- Produção requer identidade de serviço, rotação de chave, vault, nonce durável comum, autorização de tenant e contrato via fonte oficial, além de testes de rede e isolamento.

P0-DOC01–14, RF-PEND-01/04, RV-CT, LGPD e AppSec permanecem ABERTOS. `main`, VPS, Cloudflare e dados reais intocados. AGI-11-RB01 desabilitado.


## F1-359 — do encoder Portal real ao ingresso Sites (laboratório)

Ampliado o workflow atual com tests/f359-portal-signer-to-sites-ingress.php, mantendo checkout somente leitura de rafhael100/imagine-portal fixo em bf1b2794de4d6000f120ffa2b1e6dd557f34c35e. Para Onepage e Landing Page, o fluxo constrói CreateProject por SitesCreateProjectEncoder **real do código candidato Portal**, assina com SyntheticSitesEnvelopeSigner real do Portal, e valida no SyntheticSitesOrderIngress do Sites com duas atestações independentes e idempotência em memória.

São verificados: comando canônico correto, fila privada não publicável, empresa exata, tentativa repetida com nonce novo deduplicada, replay negado, revogação independente negando uma ordem assinada e adulteração de tenant recusada. Sem exigir permissões adicionais no Portal nem usar chaves de produção.

**CI comprovado:** PostgreSQL não participa; PHP 8.3/8.4 [run #43492243652](https://github.com/rafhael100/imagine-sites/actions/runs/43492243652), ambos os jobs concluíram em success. Esta é paridade de bibliotecas de um **commit candidato fixado**, não um contrato entre serviços executando HTTP, checkout/pagamento confirmado ou homologação Portal real. Se a origem do Portal mudar, revisar e repinar somente com aprovação de compatibilidade. Os gates #33/#34 continuam em aberto.
