# F1-298 — verificação independente do pacote privado de Onepage/Landing Page

**13/11/2026 | Somente laboratório CLI, offline, dados sintéticos | NO-GO produção**

## Resultado
Novo comando `sites:verify-fixture-bundle {product} {artifactDigest} {bundleDigest}` no Laravel isolado. Ele lê arquivos previamente gravados na pasta privada do produto e compara, de modo independente do comando gerador, dois hashes que o chamador informa: SHA-256 do HTML e SHA-256 do pacote completo `HTML + NUL + manifesto`.

## Garantias e testes
- Exige `APP_ENV=testing`, SAPI `cli`, `SITES_SYNTHETIC_BUNDLE=1` **e** `SITES_SYNTHETIC_VERIFY=1` (sem HTTP, dados reais, escrita, IAM ou segredos).
- Só aceita `onepage` e `landing_page` e hashes hexadecimais lowercase de 64 posições.
- Lê exclusivamente `index.html` e `release-manifest.json`; recusa symlinks, caminhos inesperados, arquivos adicionais, formatos e tamanhos incorretos.
- Usa `SyntheticReleaseBundle::verifyExactBundle` (F1-296) para conferir manifesto canônico, escopo, noindex, HTML inerte e hashes; falha por padrão.
- Testes Laravel cobrem ausência de flag, sucesso dos dois produtos, outro escopo, hashes divergentes, path traversal, arquivos extras, manifesto modificado e ausência de efeitos de escrita.
- Workflow F1-24 executa comandos de geração e de verificação privada em PHP 8.3/8.4, com hashes recalculados do fixture gerado somente para consistência técnica. Essa demonstração **não fornece um pin confiável do Portal**.

## Não coberto
A origem dos hashes não é autenticada: isso não concede assinatura, aceite expresso, IAM S2S, prova Cloudflare, observação do site servido, entrega CRM nem liberação de site. Uma prova de produção exigirá pin aprovado de release por fonte de confiança independente, controle de concorrência durável e os gates OP01–OP05 / #281.

**Proibido:** merge/deploy automático, segredos, clientes, dados reais, custos, DNS/Cloudflare/VPS e remoção dos 10 bloqueios de prontidão.
