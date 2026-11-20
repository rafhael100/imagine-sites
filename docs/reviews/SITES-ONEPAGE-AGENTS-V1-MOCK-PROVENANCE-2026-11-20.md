# OnePage → Imagine AI Agents: ensaio v1 com agente simulado e SHA da prévia

Data de execução: 20/11/2026. **NO-GO operacional.** Nova branch filha da HEAD imutável de Sites #576 (`752ad11a8ac5dabe2de00ae838553c9b910fb67b`). Sites #541/#542 são históricos; Landing Page #569 é uma trilha independente e não recebe provas deste ensaio.

## Inspeção e reuso antes da implementação

- Sites #570–#572: confirmação exata e aprovação/revogação para prévia OnePage, sem fonte transacional oficial do Portal. Sites #573: assinatura e invalidade do aceite após retrabalho. Sites #574: SHA efetivamente servido e reversão local. Sites #575/#576: alçada técnica independente e epoch por operação. **Nenhuma regressão de aceite/publicação foi reimplementada.**
- Portal #976: `ApprovedOnepageBriefSnapshotSource` e `CurrentOnepageBriefApprovalWitness` permanecem sem adaptadores operacionais; confirmação humana de direitos, revisão imutável com bytes oficiais, revogação e CAS não estão demonstrados. Portal #1003/Sites #552: contrato S2S já testado, sem identidade mTLS real; não alterado.
- `StructuredCopyCandidate::assemble` no Sites aceita **exatamente** `sites.ai-copy.v1` com cinco campos: `schemaVersion`, `headline`, `description`, `callToAction`, `benefits`. O v4 histórico do Sites #284 **não** foi usado. `StaticSiteRenderer` e `InertPreviewAudit` foram reutilizados.
- `imagine-ai-agents`: o projeto mantém a central de agentes; nenhum worker OnePage operacional, endpoint S2S privado ou credencial autorizada **disponível neste ambiente de execução** foi demonstrado. O callback isolado representa a fronteira do worker, não um serviço real nem uma afirmação de chamada API.

## Novo ensaio implementável (somente CI isolado)

1. Carrega o **mesmo** pedido de teste `order-synthetic-onepage-001`, arquivo `onepage-brief.synthetic.json`, snapshot `brief-snapshot.synthetic.json` e seus bytes exatos. Recalcula o descritor de payload e valida tudo com `BriefSnapshotInput`. Fixture aprovada no laboratório **não é fonte oficial** nem prova de identidade.
2. Sites monta `sites.onepage-agent-task.v1` determinístico, com correlação, versão de agente `imagine.onepage-agent.mock.v1`, revisão e SHA do briefing. Só entram sugestões genéricas **fictícias e estáticas**; empresa, pedido, marca, endereço, contrato, release, identidade e corpo rico do briefing **não são enviados ao worker**. O Sites mantém esses elementos e hashes no contexto local de procedência.
3. O callback simulado de Imagine AI Agents recebe um único JSON versionado e devolve `sites.onepage-agent-response.v1`, associado ao `taskRef`, `correlationRef`, revisão, digest e versão do agente. Não existe retry, loop, HTTP externo, provedor, credencial, novo fornecedor ou cobrança. O `runRef` exige prefixo `mock-`; `executionMode=simulated` não pode ser promovido a `real` pelo modelo.
4. O Sites valida JSON estrito e limitado, rejeita chaves duplicadas/extra, paridade incorreta e qualquer v4. O conteúdo `sites.ai-copy.v1` passa pelo validador já existente, com **produto e marca definidos pelo Sites**, não pelo modelo; o renderizador HTML fixo produz artefato inerte e `noindex`, sem formulário.
5. Os testes escrevem a prévia em diretório efêmero com permissões restritas, acessam-na por HTTP **somente 127.0.0.1** e verificam SHA-256 do corpo efetivamente lido. O arquivo e o processo são eliminados após o teste; não se cria URL privada autenticada para o cliente.
6. A evidência sanitizada em CI vincula `sourceVersion`, `briefRevision`, `manifestSha256`, `briefSha256`, `taskSha256`, `agentVersion`, `runRef` **simulado**, `acceptedCopySha256` e `htmlSha256`. O teste verifica também diferença de revisão, replay entre empresas, hash divergente, campos indevidos, HTML malicioso, tamanho, versão v4 e ausência de autoridade comercial.

Arquivos desta candidata:
- `src/Production/OnepageAgentCopyLabCandidate.php`
- `tests/onepage-agent-v1-mock-chain-offline.php`
- `.github/workflows/sites-onepage-agent-v1-mock-chain.yml`

## Bloqueios e próxima ação

**Nenhuma chamada OpenAI real nesta PR.** A autorização de consumir créditos foi reconhecida, mas não demonstra, por si, a existência de execução privada com **credencial já provisionada para OnePage, saída de rede autorizada, identidade isolada da central e auditoria S2S**. Não procurar/copiar/imprimir segredos, não compartilhar a chave do Faísca, nem usar segredos em CI público. Próxima etapa: no ambiente privado previamente autorizado, adaptar apenas o worker central ao contrato v1 e registrar metadados não secretos de uma chamada real **se** tais capacidades forem comprovadas. Aplicar limite finito de chamadas, timeout e saída estrita; não modificar o esquema `sites.ai-copy.v1`.

O Portal ainda precisa provar origem oficial transacional dos bytes imutáveis, comprador confirmado com direitos, aprovação/revogação independente e epochs persistentes. A prévia privada de teste não é aceite jurídico, entrega, revisão humana nem autorização técnica para publicar. Integração da interface privada #486 com identidade real e publicação/domínio seguem fases separadas. Sites #38 e os dez gates em **NO-GO**, sem merges, main, deploy, VPS, DNS, Cloudflare, Asaas, checkout, APIs reais, Replit ou novas credenciais.

CI e SHA final: preencher apenas após observar o GitHub Actions da HEAD exata; não presumir sucesso.
