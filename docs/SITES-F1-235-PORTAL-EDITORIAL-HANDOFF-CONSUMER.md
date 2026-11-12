# F1-235 — consumir o envelope editorial F48 do Portal, sem promovê-lo a autoridade

**Fonte independente:** `rafhael100/imagine-portal` em `dev/portal-f48-sites-handoff-contract-v1-2026-11-09` (SHA `d492115d6dc2cef35744817cf9e697a33131239f`), classe `SiteEngineHandoffPreviewEnvelope.php`.

A fase documental do Portal já define o JSON `imagine.portal.sites-handoff-preview.v1`, com 16 campos canônicos. Antes desta entrega, o Sites não possuía um validador próprio desse formato. `PortalEditorialHandoffPreviewV1::fromCanonicalJson()` agora verifica:

- UTF-8, até 1024 bytes, JSON de objeto e **ordem exata** de todos os 16 campos;
- serialização byte a byte idêntica, negando duplicações, campos extras e whitespace não canônico;
- origem e destino fixos (`imagine-portal` → `imagine-sites-engine`), organização e site **DEMO** válidos, produto conhecido, revisões e SHA-256 do conteúdo;
- rótulo `approved_demo` separado de `authenticated_customer_approval=false`, `external_delivery_enabled=false` e `publishing_enabled=false`.

É permitido somente mapear `sites_onepage` para `onepage` e `sites_landing_page` para `landing_page` como **metadados de demonstração**. A classe nunca cria projeto, consulta o Portal, autentica tenant, envia mensagem, captura CRM, executa IA, concede aceite, indexa ou publica. O fingerprint SHA-256 não é assinatura.

**Testes:** suíte pura `tests/f235-portal-editorial-handoff-consumer.php` em PHP 8.3/8.4; cobre 12 variações de empresa/produto/revisão e casos negativos de rótulos, tipos, permissão, replay implícito por canonicalização, formato e campos desconhecidos.

**Próxima dependência:** teste cruzado utilizando a classe **real** do Portal F48 em SHA imutável, antes de qualquer discussão de API. A prévia é editorial, não o contrato `CreateProject` e não pode substituí-lo.
