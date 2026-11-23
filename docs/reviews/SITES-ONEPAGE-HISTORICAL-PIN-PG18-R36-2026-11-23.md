# OnePage R36 — releitura histórica em PostgreSQL 18 descartável (NO-GO)

Base: Sites #639, `4965d6574882ebe55a85441e11b1d12cf378044b`, 4 workflows SUCCESS. A R35 compara uma fonte histórica injetada, mas sua fixture reside em memória. Esta candidata testa somente a leitura independente do mesmo registro sintético após commit/reconexão, por três logins SQL distintos com RLS por tenant/comprador e sem privilégios de escrita. O SELECT usa todos os cinco identificadores; não há fallback ou seleção por latest. O registro é capturado uma vez dos dados R35, não recalculado pelo leitor a partir do estado corrente. Direitos ou plano substituídos não tornam o aceite anterior válido.

A origem é explicitamente fictícia. Não é clique de navegador, autenticação do comprador, armazenamento Portal oficial, prova de persistência após desastre, CAS entre serviços, nem writer/reader operacional. O admin da fixture pode alterar seus dados; a imutabilidade é demonstrada apenas no papel leitor. Nenhuma migration do produto, novo endpoint, autorização ou concessão operacional. O serviço PG18 local é descartado pelo runner hospedado; credenciais literais são exclusivamente dados sintéticos de teste.

## Inspeção de fontes e fronteiras

- Portal #976 define fonte de snapshot imutável e testemunho corrente, ambos default-deny; #1099 (16b225a464422570e07430e900275bfcd5813970) mantém essa fronteira.
- Portal #948 (7d3b5184a961c00d75cef67416ad628d5d32da2d), Scenario.php, faz login/MFA e lê projeção `next_step=briefing` após admissão fictícia; não fornece bytes aprovados nem declaração de direitos/revisor.
- Portal #1090/#1093 têm recibos e pin no Node/SQLite legado, explicitamente não a fonte Laravel oficial. Não importar nem promover.
- Sites #605 (9c5bf8b1e41098e7c920bd21fd284e3b808051cd) grava decisão de fixture; AcceptFirstSyntheticWitness contém snapshot/manifest/revisões mas não os dois digests da R35 nem autenticação Portal. Não preencher por inferência.
- #541/#542, Portal #1003/Sites #552 e #574 permanecem precedentes distintos. Nenhuma repetição da troca/reversão ou da simulação AI Agents #490.
- Landing Portal #1088 preservada, sem herança de provas.

Próximo elo: fonte oficial de direitos/aprovação revogável e histórico do clique exato no Portal, com identidade verificada e persistência transacional. Só então ligar a fonte real ao leitor, com mTLS e gates. Worker OpenAI exclusivo continua sem atestação de execução privada. Sites #38 NO-GO, sem merge, deploy, checkout, VPS, DNS, Cloudflare ou publicação. CI R36 deve ser confirmada na HEAD exata; este documento não afirma PASS antes dos jobs.
