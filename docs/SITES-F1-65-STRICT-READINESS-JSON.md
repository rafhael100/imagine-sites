# F1-65 — Leitura estrita do inventário operacional

Endurece o leitor do F1-64 sem alterar o significado do readiness: os dez gates continuam `false`, `status=BLOCKED`, `operationallyVerified=false` e `releaseAuthorized=false`.

O parser agora rejeita chaves JSON duplicadas em qualquer profundidade, inclusive duas declarações contraditórias da mesma autorização; também recusa constantes não padronizadas `NaN`/`Infinity`. A data declarativa `checked_at_reference` deve ser calendário ISO `YYYY-MM-DD` válido. Os testes negativos executam `python3 ci/f64_readiness_inventory.py --self-test` dentro do mesmo job já existente.

Não comprova que a data seja atual, que evidências operacionais existam ou que o arquivo seja assinado; tampouco substitui análise humana de mudanças. Nenhuma consulta a VPS, Cloudflare, Portal ou CRM; sem credenciais, clientes, gastos, publicação, merge ou autorização de produção. Este PR depende do F1-64 (#85) e mantém a branch `main` inalterada.
