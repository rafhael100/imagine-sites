# SITES-F1-08 — PostgreSQL 18: revisões, hashes e evidências isoladas

**Natureza:** banco real **descartável em CI**, sem migrations produtivas ou aprovação de release real. Este recorte depende de F1-01..07 e permanece em PR draft.

O banco experimental `sites_lab` recebe tabelas `lab_sites.releases` (versões e digest de artefatos), `lab_sites.client_acceptances` (recibos fictícios de aceite originados da autoridade Portal) e `lab_sites.publication_observations` (observações fictícias de publicação). Elas têm chaves compostas tenant/site/release e RLS habilitada/forçada; runtime tem `SELECT` limitado e `INSERT` somente em release draft, sem permissão para criar aceite ou afirmar deploy.

**Segurança testada:** negação de leitura entre tenants, de inserção em tenant alheio, de versão duplicada, de digest inválido, de mutação/published, de recibo de aceite ou observação falsificados; comparação do digest da release com os digests de aceite e observação; negação após revogação. Ausência de qualquer prova não significa publicado. Nem uma resposta do provedor é prova sem verificação independente.

**Restrições:** os recibos são injetados por fixture de DB owner apenas para testar as propriedades SQL. Não existe autenticação real Portal↔Sites nem Cloudflare. Uma igualdade entre hashes prova igualdade de bytes, mas não identidade do cliente, pagamento, autorização, saúde da publicação ou ENTREGUE. Essas capacidades dependem de API assinada, RLS e decisão no Portal, com gates P0/RF-PEND ainda abertos.

Código SQL não entra em `database/migrations` e recusa execução em base diferente de `sites_lab`. Não rodar scripts em VPS ou banco persistente. Sem merge/deploy automático; AGI-11-RB01 continua desabilitado.

**Próximo:** pipeline de publicação mockado, que só retorna elegibilidade consultiva após checagem de versão aprovada e prova independente.
