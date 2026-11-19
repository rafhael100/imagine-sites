# Portal → Sites: recusa de planos válidos mas sem vínculo de origem exata

**19/11/2026.** Prova candidata em PostgreSQL 18 descartável, sem publicação/aceite/autorização real.

## Base congelada e lacuna

A [#541](https://github.com/rafhael100/imagine-sites/pull/541) demonstrou o snapshot e plano positivo emitidos pelos exportadores candidatos Portal até o registro nativo. A [#542](https://github.com/rafhael100/imagine-sites/pull/542) testa entradas de *snapshot* válidas mas com tenant, contrato ou revisão inadequados, recusadas no recorder. Este recorte testa **o ponto seguinte**: planos de renderização completos, válidos para o parser e emitidos pelo encoder Portal, mas que não selecionam os **bytes exatos** gravados para o leitor e writer atuais, ou não batem com o renderer real.

Base exata da #542: `44c09c98c2e02706c1b6bcbe3112e6342de4e9ea`. Produtores Portal fixados em `7acee97ebaf474d630415ca456a616b5e12f2df7` ([Portal #972](https://github.com/rafhael100/imagine-portal/pull/972)). Não se altera arquivo do Portal.

## O que a fixture executa

A fixture `tests/fixtures/portal-render-plan-boundary/contract.php` só entra com **três opt-ins CI**, depois de o parser e recorder nativos terem gravado o primeiro snapshot A e de terem construído o plano Portal original. As mesmas classes reais `OnepageBriefSnapshotCandidateEncoder`, `OnepageRenderPlanCandidateEncoder`, `BriefRenderPlanInput` e `StoredBriefReleaseRegistration` participam da prova.

O caso positivo planeja os bytes originais em modo somente leitura. Nove casos negativos passam no parser Sites, sem forjar JSON quebrado: referência ainda não gravada; UUID de snapshot reutilizado com manifesto divergente; revisão futura; metadata tenant B sobre site A; site B sob leitor A; fonte rica modificada sob o mesmo UUID; editorial alterado conservando digest HTML original; digest HTML errado; tamanho HTML errado. **Plan** e **record** são tentados com compromisso determinístico correspondente aos bytes candidatos. Ambos devem devolver `BRIEF_ARTIFACT_PROVENANCE_DENIED` sem exceção aninhada, alteração de linhas ou mudança de catálogo. O plano positivo continua utilizável depois dos negativos.

O resultado não prova que snapshots, conteúdo editorial e aprovações sejam autenticamente emitidos por um Portal operacional. O campo `sourceState=approved` é apenas metadata sintética. Mesmo o HTML tecnicamente verificável não constitui anuência do cliente ou liberação de domínio.

## Verificação e restrições

O workflow dedicado exige PR draft, branch filha exata, HEAD congelada da base, checkout imutável do Portal, PHP 8.3/8.4, SCRAM/TLS em PostgreSQL18 temporário e limpeza do laboratório. Preserva os marcadores legados (incluindo 1.279 verificações registry), exige o marcador exclusivo `PORTAL RENDER PLAN BOUNDARY PG18 PASS`, e não publica arquivos.

**Gates comerciais e de implantação permanecem BLOCKED:** pagamento, aprovação/autenticidade do briefing, identidade, consentimento, cliente, artefato, revisão oficial, publicação, DNS e aceite. Nenhum merge, deploy, acesso à VPS, Cloudflare, produção ou cobrança está autorizado por este ensaio.

**Estado:** evidência de CI deve ser preenchida somente após executar e conferir logs na HEAD final.
