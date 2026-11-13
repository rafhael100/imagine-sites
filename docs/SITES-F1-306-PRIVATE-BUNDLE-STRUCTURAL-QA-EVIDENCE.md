# F1-306 — QA estrutural opt-in no relatório privado

**Escopo:** laboratório local CLI/Laravel, dados exclusivamente fictícios, sem serviço HTTP, cliente, publicação, rede ou novos custos.

## Objetivo

A opção `--evidence-json` da F1-305 relata apenas a integridade local de dois arquivos. Um operador interno também precisa conhecer, em formato legível por máquina, se o HTML verificado passou nos dez critérios estruturais já definidos no motor (`SyntheticPreviewQaReport`), sem expor conteúdo do site.

## Contrato

Somente após a verificação **exata** dos dois digests recebidos e dos arquivos privados:

```text
APP_ENV=testing SITES_SYNTHETIC_BUNDLE=1 SITES_SYNTHETIC_VERIFY=1 \
php apps/sites/artisan sites:verify-fixture-bundle onepage <artifact-sha256> <bundle-sha256> \
  --evidence-json --include-qa
```

- `--include-qa` requer `--evidence-json`; uso isolado falha.
- O resultado mantém `schemaVersion=sites.synthetic.private-bundle-evidence.v1` e os campos legados sem modificação. Apenas quando solicitado, acrescenta `structuralQa` com `passed`, `checks`, `humanReviewRequired=true` e `wcagCertified=false`.
- A inspeção usa **somente** os bytes exatos já verificados, dentro do lock de leitura F1-300/F1-303. HTML, manifesto, nomes de cliente e paths não são impressos.
- Falha de integridade ou de inspeção impede resposta de sucesso. Não há fallback silencioso.
- Saída idêntica para mesmos arquivos e digests; não introduz timestamps, randomização ou consulta externa.

## Verificações e limites

`SyntheticPreviewQaReport` cobre o template fixo e fictício: markup inerte, hash, idioma/título, main/h1, âncoras, labels de seções, nomes dos links, viewport/noindex, ausência de elementos executáveis e semântica de FAQ. **Não é auditoria WCAG, prova editorial, aprovação do cliente, certificação AppSec ou autorização do Portal.**

A F1-306 NÃO cria direito de publicação. `trustedPins=false`, `officialClientAcceptance=false`, `releaseAuthorized=false`; `config/operational-readiness.json` permanece 10/10 bloqueado. Gates #281 e OP01–OP05 (#33–#38), integridade distribuída, identidade/aceite reais, Cloudflare e revisão humana permanecem externos ao laboratório.
