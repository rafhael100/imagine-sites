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
