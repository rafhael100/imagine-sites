# F1-236 — compatibilidade de produtor Portal F48 e consumidor Sites F1-235

Os testes do Sites deixam de depender apenas de um JSON escrito manualmente: no GitHub Actions, o workflow faz checkout **somente leitura** da classe real `SiteEngineHandoffPreviewEnvelope.php` do repositório público `rafhael100/imagine-portal`, no SHA imutável `d492115d6dc2cef35744817cf9e697a33131239f`.

O script `tests/f236-portal-f48-producer-consumer-parity.php` instancia a própria classe do Portal e entrega o retorno de `canonicalJson()` ao novo consumidor do Sites. Testa 16 combinações de empresa, produto e revisões, conferindo bytes JSON canônicos, ordenação de campos, SHA-256 e mapeamento dos produtos. Tentativas de atribuir autorização operacional ou origem não confiável são negadas.

**Critério de compatibilidade:** qualquer alteração de contrato no Portal que modifique tipo/ordem/campos/enum/documento e quebra de digest deve falhar no consumidor e na CI, exigindo nova versão negociada, não um fallback silencioso.

**Limitação:** as classes F48 do Portal têm organização e revisão **fictícias**; `approved_demo` e um hash correto **não** são identificação de cliente, contrato vigente, aceite expresso, assinatura S2S ou permissão de publicar. O workflow não instancia serviços reais, HTTP, banco, filas, n8n, VPS, Cloudflare ou CRM. Os testes são executados em PHP 8.3 e 8.4 apenas em runner descartável; `dev`/`main` permanecem intocadas.
