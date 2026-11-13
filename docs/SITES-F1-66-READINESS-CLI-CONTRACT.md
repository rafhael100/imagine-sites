# F1-66 — Teste de contrato do CLI de readiness

O teste `python3 ci/f66_readiness_cli_contract.py` exercita o CLI real `ci/f64_readiness_inventory.py` como **subprocesso**, em três modos: `--json`, apresentação humana e `--self-test`. Verifica que duas saídas JSON são determinísticas; os dez gates se mantêm BLOQUEADOS e unicamente identificados; todos os links de issues são os previstos (#33–#38); e as representações JSON e humana concordam nas pendências.

Integrado no job F1-21 preexistente. Não altera o payload nem seus significados. Testa o contrato do relatório, **não** a infraestrutura, as evidências de cliente, a identidade, a aprovação ou o aceite. `operationallyVerified=false` e `releaseAuthorized=false` continuam os únicos valores permitidos. Nenhuma chave, VPS, Cloudflare, DNS, dados reais, custos, deploy ou merge são introduzidos.

Dependências da cadeia: #85 (F1-64) e #86 (F1-65). O teste deve passar no GitHub Actions antes de qualquer integração controlada.
