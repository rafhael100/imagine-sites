# F1-119 — Paridade real do emissor Portal v2 com o verificador Imagine Sites

## Evidência entre repositórios

A F1-118 acrescentou um envelope sintético HMAC v2 no Imagine Sites, com revisão autenticada da chave `keyEpoch`. A F1-119 comprova que um **emissor PHP separado**, agora desenvolvido no Imagine Portal ([PR Portal #258](https://github.com/rafhael100/imagine-portal/pull/258)), produz exatamente o mesmo envelope e que o Sites o verifica.

O workflow `.github/workflows/sites-f1-23-cross-repo-contract.yml` obtém a revisão pública fixa do Portal `855212e93649235715202390f296444d91b6609e`, não uma branch móvel. Valida o SHA e os arquivos do encoder, emissor v1 e emissor v2, e executa `tests/f23-cross-repo.php` em PHP 8.3/8.4.

Para Onepage e Landing Page, o teste:
- usa o verdadeiro `SitesCreateProjectEncoder` do Portal candidato com dados fictícios de pedido/contrato;
- compara **todo o envelope PHP** de `SyntheticSitesVersionedEnvelopeSigner::sign` e `SyntheticVersionedSignedCommand::sign`, incluindo a MAC;
- verifica no Sites o HMAC v2 emitido pelo Portal, o payload, a autorização por estrutura (não oficial) e o bloqueio de replay;
- exige negação por `keyEpoch` obsoleto **antes** de registrar nonce, permitindo assinar novamente com revisão atual;
- comprova que apenas alterar o `keyEpoch` de uma mensagem antiga invalida HMAC;
- comprova que a v1 anterior não é aceita como envelope v2.

## Limitações

Os dois repositórios têm fontes PHP independentes, mas ainda são **protótipos sintéticos de laboratório**. O checkout do Portal está fixado no SHA do PR #258, não na versão operacional atual do Portal. Uma prova de paridade byte a byte não autentica emissor real, sessão, contrato comercial, pagamento, aceite do cliente, revogação distribuída, segredo de produção nem autorização de publicação.

O Portal não disponibiliza um serviço oficial para emissão de v2. Antes de homologar, são necessários fonte real de revisão de chave, vault, mTLS/OAuth ou arquitetura equivalente aprovada, revogação fora do domínio de restauração, contrato versionado aprovado nos dois projetos e testes HTTPS com credenciais apenas de homologação.

Nenhum merge em dev/main, deploy, dado pessoal, publicação, DNS, VPS ou nova despesa. **10/10 gates operacionais BLOCKED**.
