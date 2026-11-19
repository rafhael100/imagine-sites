# Segurança da entrada Portal candidata: contexto, CAS e replay no Sites

**19/11/2026 — prova isolada, sem autorização ou dados reais.**

## Por que este recorte existe

A [PR #541](https://github.com/rafhael100/imagine-sites/pull/541)
comprovou o caminho positivo da primeira e da segunda revisão Onepage
geradas pelos exportadores candidatos Portal, passando pelo
`PortalBriefSnapshotRecorder`, persistência PostgreSQL18 e registro da
release com proveniência. A execução passou nos 17 checks da HEAD
`99aabc8300b58a1494287a841c71bb302224de44`.
A próxima pergunta de segurança é se um manifesto Portal **sintaticamente
válido e com SHA correto**, mas com contexto estranho ou revisão obsoleta,
poderia ser gravado por uma identidade do Sites que não deveria autorizá-lo.

## Ensaios sem alterar os contratos de produto

`tests/fixtures/portal-brief-adversarial/contract.php` é incluído logo
após o recebimento válido do snapshot candidato. Primeiro executa um
**replay positivo real** e comprova que o banco não cria outra linha.
Depois cria oito manifestos pelo mesmo exportador Portal pinado e passa
todos pelo parser real do Sites: não são JSONs malformados, com SHA errado
ou recusados pela camada offline.

Cada cenário faz uma única chamada ao recorder nativo, em um PostgreSQL18
descartável com SCRAM/TLS e receiver restrito:

1. Tenant B declarado para o site e contexto de A.
2. Site de B usado com a identidade receiver A.
3. Referência de contrato de outro projeto do tenant A.
4. Referência de pedido de outro projeto do tenant A.
5. `sourceVersion` não avança no projeto A.
6. CAS de revisão antiga (`expectedBriefRevision=0` depois da revisão 1).
7. A mesma operação/idempotência/snapshot original com bytes do corpo alterados.
8. Identificador UUID de projeto inexistente, embora o contexto pareça válido.

Cada input válido antes da SQL precisa ser negado pela camada nativa,
com `BRIEF_SNAPSHOT_DENIED` ou conflito específico, sem mutação de nenhuma
linha, sem trocar o tenant da sessão e sem aceitação de origem inventada.
Ao final, o replay original continua funcionando e os snapshots integrais
de linhas e catálogo do laboratório são idênticos.

A suite original não é modificada em suas contagens: o marcador separado
`PORTAL BRIEF ADVERSARIAL PG18 PASS` pertence exclusivamente a este
incremento e é exigido pelo workflow draft da candidata.

## Isolamento e execução

A branch deriva da #541 já validada. O workflow
`sites-portal-brief-adversarial-pg18.yml` exige a base/branch exatas,
PHP 8.3 e 8.4, `SITES_PORTAL_BRIEF_EXPORT_CI=1` e o opt-in adicional
`SITES_PORTAL_BRIEF_ADVERSARIAL_CI=1`. Reutiliza o checkout público
Portal [#972](https://github.com/rafhael100/imagine-portal/pull/972)
pinado em `7acee97ebaf474d630415ca456a616b5e12f2df7`, sem
credenciais persistidas.

A prova não instala funções SQL, permissões, logins operacionais, rotas,
webhook, provedores, n8n ou serviços na VPS. O banco e os atores são
inteiramente descartáveis; o guard de produção permanece bloqueado.
O texto `sourceState=approved` ainda é declaração do contrato candidato,
não uma aprovação verificada pelo Portal.

A execução CI deste recorte deve ser confirmada antes de declarar os
cenários validados. Não é um transporte oficial do briefing.
